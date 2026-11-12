import argparse
import json
import logging
import os
import sys
import time
from pathlib import Path
from typing import Any

import requests
from tenacity import (
    retry,
    retry_if_exception_type,
    stop_after_attempt,
    wait_exponential,
)


logging.basicConfig(
    level=logging.INFO, format="%(asctime)s - %(levelname)s - %(message)s"
)

PROJECT_ROOT = Path(__file__).resolve().parent.parent
DEFAULT_OUTPUT = PROJECT_ROOT / "output" / "zoominfo_data.json"

# Bright Data's synchronous /scrape endpoint accepts a limited number of
# inputs per call, so larger jobs are sent in batches.
MAX_INPUTS_PER_REQUEST = 20

# 429 and 5xx are worth retrying; other 4xx (bad token, bad dataset id,
# malformed input) will fail the same way every time.
RETRYABLE_STATUS = {408, 425, 429, 500, 502, 503, 504}


class ScrapeError(Exception):
    """A failure that retrying will not fix."""


def load_env_file(path: Path = PROJECT_ROOT / ".env") -> None:
    """Load KEY=VALUE lines from .env without overriding real env vars."""
    if not path.is_file():
        return
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, value = line.partition("=")
        os.environ.setdefault(key.strip(), value.strip().strip("\"'"))


class ZoominfoCollector:
    SCRAPE_URL = "https://api.brightdata.com/datasets/v3/scrape"
    PROGRESS_URL = "https://api.brightdata.com/datasets/v3/progress/{sid}"
    SNAPSHOT_URL = "https://api.brightdata.com/datasets/v3/snapshot/{sid}"
    DATASET_ID = "gd_m0ci4a4ivx3j5l6nx"

    def __init__(self, api_token: str, dataset_id: str | None = None):
        self.api_token = api_token
        self.dataset_id = dataset_id or self.DATASET_ID
        self.headers = {
            "Authorization": f"Bearer {api_token}",
            "Content-Type": "application/json",
        }

    @retry(
        retry=retry_if_exception_type(requests.exceptions.RequestException),
        stop=stop_after_attempt(3),
        wait=wait_exponential(multiplier=2, min=2, max=30),
        reraise=True,
    )
    def _post(self, inputs: list[dict], params: dict[str, Any]) -> list[dict]:
        response = requests.post(
            self.SCRAPE_URL,
            headers=self.headers,
            params=params,
            json=inputs,
            timeout=120,
        )
        if not response.ok and response.status_code not in RETRYABLE_STATUS:
            raise ScrapeError(
                f"HTTP {response.status_code} from Bright Data: "
                f"{response.text[:500]}"
            )
        response.raise_for_status()
        return response.json()

    def _snapshot_id(self, data: Any) -> str | None:
        """The sync endpoint gives up on slow jobs and hands back a snapshot id."""
        if isinstance(data, dict):
            return data.get("snapshot_id")
        if isinstance(data, list) and len(data) == 1 and isinstance(data[0], dict):
            return data[0].get("snapshot_id")
        return None

    def _await_snapshot(
        self, snapshot_id: str, timeout: int, interval: int
    ) -> list[dict] | None:
        """Poll a snapshot to completion, then download it."""
        logging.info(
            f"Job queued as snapshot {snapshot_id}; polling up to {timeout}s..."
        )
        deadline = time.monotonic() + timeout

        while time.monotonic() < deadline:
            try:
                progress = requests.get(
                    self.PROGRESS_URL.format(sid=snapshot_id),
                    headers=self.headers,
                    timeout=30,
                ).json()
            except (requests.exceptions.RequestException, ValueError) as e:
                logging.warning(f"Progress check failed, will retry: {e}")
                time.sleep(interval)
                continue

            status = str(progress.get("status", "")).lower()

            if status in {"ready", "done", "completed"}:
                logging.info("Snapshot ready, downloading...")
                return self._download_snapshot(snapshot_id)

            if status in {"failed", "error", "canceled", "cancelled"}:
                logging.error(f"Snapshot {snapshot_id} ended as '{status}': {progress}")
                return None

            logging.info(f"  status={status or 'unknown'}, waiting {interval}s")
            time.sleep(interval)

        logging.error(
            f"Timed out after {timeout}s. The job may still be running; "
            f"resume it with: --snapshot {snapshot_id}"
        )
        return None

    def _download_snapshot(self, snapshot_id: str) -> list[dict] | None:
        try:
            response = requests.get(
                self.SNAPSHOT_URL.format(sid=snapshot_id),
                headers=self.headers,
                params={"format": "json"},
                timeout=120,
            )
            response.raise_for_status()
            data = response.json()
        except (requests.exceptions.RequestException, ValueError) as e:
            logging.error(f"Could not download snapshot {snapshot_id}: {e}")
            return None
        return data if isinstance(data, list) else [data]

    def fetch_snapshot(
        self, snapshot_id: str, output: Path, timeout: int = 600, interval: int = 10
    ) -> list[dict] | None:
        """Resume a previously queued job by id."""
        records = self._await_snapshot(snapshot_id, timeout, interval)
        if records is not None:
            self._report(records)
            self._save_data(records, output)
        return records

    @staticmethod
    def _report(records: list[dict]) -> None:
        """Separate real records from Bright Data's per-URL error rows."""
        errors = [r for r in records if isinstance(r, dict) and r.get("error")]
        usable = [
            r for r in records
            if isinstance(r, dict) and not r.get("error") and (r.get("id") or r.get("name"))
        ]
        logging.info(f"{len(usable)} usable record(s), {len(errors)} error row(s)")
        for err in errors[:10]:
            url = (err.get("input") or {}).get("url", "?")
            logging.warning(f"  {err.get('error_code', 'error')}: {url} - {err.get('error')}")

    def collect_data(
        self,
        inputs: list[dict],
        output: Path = DEFAULT_OUTPUT,
        extra_params: dict[str, Any] | None = None,
        batch_size: int = MAX_INPUTS_PER_REQUEST,
        poll_timeout: int = 600,
        poll_interval: int = 10,
    ) -> list[dict] | None:
        params: dict[str, Any] = {"dataset_id": self.dataset_id, "format": "json"}
        params.update(extra_params or {})

        logging.info(f"Scraping {len(inputs)} url(s)...")
        records: list[dict] = []
        for start in range(0, len(inputs), batch_size):
            batch = inputs[start : start + batch_size]
            logging.info(
                f"Batch {start // batch_size + 1}: requesting {len(batch)} url(s)"
            )
            try:
                data = self._post(batch, params)
            except ScrapeError as e:
                logging.error(f"Scrape request failed: {e}")
                return None
            except requests.exceptions.RequestException as e:
                body = getattr(getattr(e, "response", None), "text", "") or ""
                logging.error(f"Scrape request failed: {e} {body[:500]}".strip())
                return None
            snapshot_id = self._snapshot_id(data)
            if snapshot_id:
                data = self._await_snapshot(snapshot_id, poll_timeout, poll_interval)
                if data is None:
                    return None

            if isinstance(data, dict):
                data = [data]
            records.extend(data)

        logging.info(f"Received {len(records)} records")
        self._report(records)
        self._save_data(records, output)
        return records

    def _save_data(
        self, data: list[dict[str, Any]], output: Path = DEFAULT_OUTPUT
    ) -> None:
        try:
            output.parent.mkdir(parents=True, exist_ok=True)
            with open(output, "w", encoding="utf-8") as f:
                json.dump(data, f, indent=2, ensure_ascii=False)
            logging.info(f"Data saved to {output}")
        except Exception as e:
            logging.error(f"Error saving data: {str(e)}")


def read_urls(args: argparse.Namespace) -> list[str]:
    urls = list(args.urls)
    if args.urls_file:
        for line in Path(args.urls_file).read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if line and not line.startswith("#"):
                urls.append(line)
    return urls


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Collect Zoominfo records via the Bright Data Scraper API."
    )
    parser.add_argument("urls", nargs="*", help="Zoominfo URL(s) to scrape")
    parser.add_argument(
        "-f", "--urls-file", help="File with one Zoominfo URL per line"
    )
    parser.add_argument(
        "-o",
        "--output",
        type=Path,
        default=DEFAULT_OUTPUT,
        help=f"Where to write the JSON results (default: {DEFAULT_OUTPUT})",
    )
    parser.add_argument(
        "--dataset-id",
        default=os.environ.get("BRIGHTDATA_DATASET_ID"),
        help="Override the Bright Data dataset id",
    )
    parser.add_argument(
        "--batch-size",
        type=int,
        default=MAX_INPUTS_PER_REQUEST,
        help=f"URLs per API request (default: {MAX_INPUTS_PER_REQUEST})",
    )
    parser.add_argument("--limit", type=int, help="Max results per input")
    parser.add_argument(
        "--include-errors", action="store_true", help="Include error details"
    )
    parser.add_argument("--notify", help="Webhook URL to call when done")
    parser.add_argument(
        "--snapshot",
        help="Resume a queued job by snapshot id instead of starting a new scrape",
    )
    parser.add_argument(
        "--poll-timeout",
        type=int,
        default=600,
        help="Seconds to wait for a queued job to finish (default: 600)",
    )
    parser.add_argument(
        "--poll-interval",
        type=int,
        default=10,
        help="Seconds between progress checks (default: 10)",
    )
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    load_env_file()
    args = parse_args(argv)

    api_token = os.environ.get("BRIGHTDATA_API_TOKEN")
    if not api_token:
        logging.error(
            "BRIGHTDATA_API_TOKEN is not set. Copy .env.example to .env and add "
            "your Bright Data API token."
        )
        return 2

    if args.snapshot:
        scraper = ZoominfoCollector(api_token, dataset_id=args.dataset_id)
        data = scraper.fetch_snapshot(
            args.snapshot, args.output, args.poll_timeout, args.poll_interval
        )
        return 0 if data is not None else 1

    urls = read_urls(args)
    if not urls:
        logging.error("No URLs given. Pass them as arguments or via --urls-file.")
        return 2

    extra_params: dict[str, Any] = {}
    if args.limit is not None:
        extra_params["limit"] = args.limit
    if args.include_errors:
        extra_params["include_errors"] = "true"
    if args.notify:
        extra_params["notify"] = args.notify

    scraper = ZoominfoCollector(api_token, dataset_id=args.dataset_id)
    data = scraper.collect_data(
        [{"url": url} for url in urls],
        output=args.output,
        extra_params=extra_params,
        batch_size=args.batch_size,
        poll_timeout=args.poll_timeout,
        poll_interval=args.poll_interval,
    )
    return 0 if data is not None else 1


if __name__ == "__main__":
    sys.exit(main())
