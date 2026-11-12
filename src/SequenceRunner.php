<?php
declare(strict_types=1);

/**
 * Processes due sequence steps. Driven by bin/run_sequences.php on a schedule.
 *
 * Guard rails, in the order they are applied: campaign must be running, the
 * clock must be inside the campaign's send window, per-hour throttle and the
 * global daily cap must not be exceeded, and the address must not be suppressed.
 */
final class SequenceRunner
{
    /** @var array<int,string> */
    private array $lines = [];

    public function __construct(private bool $dryRun = false) {}

    public function log(): array
    {
        return $this->lines;
    }

    private function say(string $line): void
    {
        $this->lines[] = date('H:i:s') . '  ' . $line;
    }

    /** @return array{processed:int, sent:int, skipped:int, failed:int} */
    public function run(?int $onlyCampaign = null): array
    {
        $totals = ['processed' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0];

        $sentToday = (int) Db::value(
            "SELECT COUNT(*) FROM messages WHERE status = 'sent' AND DATE(sent_at) = CURDATE()"
        );
        $dailyCap = (int) Settings::get('daily_send_cap', '200');

        if ($sentToday >= $dailyCap) {
            $this->say("Daily cap reached ($sentToday/$dailyCap). Nothing to do.");
            return $totals;
        }

        $sql = "SELECT * FROM campaigns WHERE status = 'running'";
        $params = [];
        if ($onlyCampaign !== null) {
            $sql .= ' AND id = :id';
            $params['id'] = $onlyCampaign;
        }

        foreach (Db::all($sql, $params) as $campaign) {
            if (!$this->inSendWindow($campaign)) {
                $this->say("Campaign #{$campaign['id']} '{$campaign['name']}': outside send window, skipping.");
                continue;
            }

            $sentThisHour = (int) Db::value(
                "SELECT COUNT(*) FROM messages
                 WHERE campaign_id = :c AND status = 'sent' AND sent_at >= :since",
                ['c' => $campaign['id'], 'since' => date('Y-m-d H:i:s', time() - 3600)]
            );

            $budget = min(
                max(0, (int) $campaign['throttle_per_hour'] - $sentThisHour),
                max(0, $dailyCap - $sentToday)
            );

            if ($budget <= 0) {
                $this->say("Campaign #{$campaign['id']}: throttle reached, skipping.");
                continue;
            }

            $due = Db::all(
                "SELECT en.*, l.* , en.id AS enrollment_id, l.id AS lead_id
                 FROM enrollments en
                 JOIN leads l ON l.id = en.lead_id
                 WHERE en.campaign_id = :c AND en.status = 'active'
                   AND en.next_send_at IS NOT NULL AND en.next_send_at <= NOW()
                 ORDER BY en.next_send_at
                 LIMIT $budget",
                ['c' => $campaign['id']]
            );

            if (!$due) {
                $this->say("Campaign #{$campaign['id']} '{$campaign['name']}': nothing due.");
                continue;
            }

            $sender = Db::one('SELECT * FROM users WHERE id = :id', ['id' => $campaign['owner_id']]) ?? ['id' => 0, 'name' => ''];

            foreach ($due as $row) {
                $totals['processed']++;
                $result = $this->processOne($campaign, $row, $sender);
                $totals[$result]++;
                if ($result === 'sent') {
                    $sentToday++;
                    if ($sentToday >= $dailyCap) {
                        $this->say("Daily cap reached mid-run. Stopping.");
                        return $totals;
                    }
                }
            }
        }

        return $totals;
    }

    /** @return 'sent'|'skipped'|'failed' */
    private function processOne(array $campaign, array $row, array $sender): string
    {
        $stepNo = (int) $row['current_step'] + 1;

        $step = Db::one(
            'SELECT s.*, t.subject, t.body_html, t.design_json, t.preheader, t.name AS template_name,
                    t.attachment_path, t.attachment_name
             FROM campaign_steps s
             JOIN templates t ON t.id = s.template_id
             WHERE s.campaign_id = :c AND s.step_no = :n',
            ['c' => $campaign['id'], 'n' => $stepNo]
        );

        if (!$step) {
            Db::update('enrollments', [
                'status'       => 'finished',
                'next_send_at' => null,
            ], 'id = :wid', ['wid' => $row['enrollment_id']]);
            $this->say("Lead #{$row['lead_id']}: sequence complete.");
            return 'skipped';
        }

        if ($this->dryRun) {
            $this->say("[dry run] would send step $stepNo '{$step['template_name']}' to " . ($row['email'] ?: '(no email)'));
            return 'skipped';
        }

        $lead = $row;
        $lead['id'] = $row['lead_id'];

        $result = Mailer::sendToLead(
            $lead,
            (string) $step['subject'],
            Templates::bodyFor($step),
            $sender,
            (int) $campaign['id'],
            $stepNo,
            Templates::attachmentFor($step)
        );

        if ($result['status'] === 'sent') {
            $this->advance($campaign, $row, $stepNo);
            $this->say("Lead #{$row['lead_id']} <{$row['email']}>: sent step $stepNo.");
            return 'sent';
        }

        if ($result['status'] === 'skipped') {
            // Nothing will change on a retry (no address, or opted out) — stop cleanly.
            Db::update('enrollments', [
                'status'       => 'stopped',
                'stop_reason'  => $result['error'],
                'next_send_at' => null,
            ], 'id = :wid', ['wid' => $row['enrollment_id']]);
            $this->say("Lead #{$row['lead_id']}: stopped — {$result['error']}");
            return 'skipped';
        }

        // A failure is usually transient (SMTP down); retry in an hour.
        Db::update('enrollments', [
            'next_send_at' => date('Y-m-d H:i:s', strtotime('+1 hour')),
        ], 'id = :wid', ['wid' => $row['enrollment_id']]);
        $this->say("Lead #{$row['lead_id']}: FAILED — {$result['error']} (retry in 1h)");
        return 'failed';
    }

    private function advance(array $campaign, array $row, int $stepNo): void
    {
        $next = Db::one(
            'SELECT delay_hours FROM campaign_steps WHERE campaign_id = :c AND step_no = :n',
            ['c' => $campaign['id'], 'n' => $stepNo + 1]
        );

        if (!$next) {
            Db::update('enrollments', [
                'current_step'  => $stepNo,
                'status'        => 'finished',
                'next_send_at'  => null,
            ], 'id = :wid', ['wid' => $row['enrollment_id']]);
            return;
        }

        $delay = max(1, (int) $next['delay_hours']);
        Db::update('enrollments', [
            'current_step' => $stepNo,
            'next_send_at' => date('Y-m-d H:i:s', strtotime("+$delay hours")),
        ], 'id = :wid', ['wid' => $row['enrollment_id']]);
    }

    private function inSendWindow(array $campaign): bool
    {
        $now = new DateTimeImmutable('now');

        if ((int) $campaign['skip_weekends'] === 1 && in_array((int) $now->format('w'), [0, 6], true)) {
            return false;
        }

        $time = $now->format('H:i:s');
        return $time >= $campaign['send_window_start'] && $time <= $campaign['send_window_end'];
    }

    /** Add leads to a campaign. Only leads with a usable, non-suppressed address. */
    public static function enroll(int $campaignId, array $leadIds): array
    {
        $added = 0;
        $rejected = [];

        foreach ($leadIds as $leadId) {
            $lead = Db::one('SELECT * FROM leads WHERE id = :id', ['id' => (int) $leadId]);
            if (!$lead) {
                continue;
            }
            if (!is_valid_email($lead['email'])) {
                $rejected[] = ($lead['name'] ?: "Lead #$leadId") . ' — no email address';
                continue;
            }
            if (Suppression::has($lead['email'])) {
                $rejected[] = ($lead['name'] ?: "Lead #$leadId") . ' — unsubscribed';
                continue;
            }
            if (($lead['email_status'] ?? null) === 'invalid') {
                $rejected[] = ($lead['name'] ?: "Lead #$leadId") . ' — email failed verification';
                continue;
            }

            Db::run(
                Db::insertIgnore() . ' INTO enrollments (campaign_id, lead_id, next_send_at)
                 VALUES (:c, :l, NOW())',
                ['c' => $campaignId, 'l' => $lead['id']]
            );
            $added++;
        }

        return ['added' => $added, 'rejected' => $rejected];
    }
}
