<?php
declare(strict_types=1);

/** Append-only activity log that the analytics screen reads. */
final class Events
{
    public static function log(string $type, ?int $messageId = null, ?int $leadId = null, ?int $campaignId = null, ?string $detail = null): void
    {
        Db::insert('events', [
            'type'        => $type,
            'message_id'  => $messageId,
            'lead_id'     => $leadId ?: null,
            'campaign_id' => $campaignId,
            'detail'      => $detail !== null ? mb_substr($detail, 0, 1000) : null,
        ]);
    }
}
