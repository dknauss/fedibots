<?php

declare(strict_types=1);

namespace Fedibots\ActivityPub;

use Fedibots\Config;
use Fedibots\Storage\StorageInterface;

final class Delivery
{
    private const BATCH_SIZE = 15;

    public function __construct(
        private Config $config,
        private StorageInterface $storage,
        private Signature $signature,
    ) {
    }

    /**
     * Broadcast an activity to all followers' inboxes.
     * Uses shared inboxes where available to reduce requests.
     * Returns number of successful deliveries.
     */
    public function broadcast(array $activity, array $followers): int
    {
        if (empty($followers)) {
            return 0;
        }

        $body = json_encode($activity, JSON_UNESCAPED_SLASHES);

        // Deduplicate by shared inbox
        $inboxes = $this->deduplicateInboxes($followers);

        $this->storage->log('delivery', 'Starting broadcast', [
            'unique_inboxes' => count($inboxes),
            'total_followers' => count($followers),
        ]);

        // Deliver in batches using multi-cURL
        $batches = array_chunk($inboxes, self::BATCH_SIZE);
        $totalSuccess = 0;

        foreach ($batches as $batch) {
            $totalSuccess += $this->deliverBatch($batch, $body);
        }

        $this->storage->log('delivery', 'Broadcast complete', [
            'successful' => $totalSuccess,
            'total' => count($inboxes),
        ]);

        return $totalSuccess;
    }

    /**
     * Deduplicate followers by shared inbox.
     * Returns unique inbox URLs.
     */
    private function deduplicateInboxes(array $followers): array
    {
        $seen = [];
        $inboxes = [];

        foreach ($followers as $follower) {
            $inbox = $follower['shared_inbox'] ?? $follower['inbox'] ?? null;
            if ($inbox === null || isset($seen[$inbox])) {
                continue;
            }
            $seen[$inbox] = true;
            $inboxes[] = $inbox;
        }

        return $inboxes;
    }

    /**
     * Deliver to a batch of inboxes using multi-cURL.
     */
    private function deliverBatch(array $inboxes, string $body): int
    {
        $multiHandle = curl_multi_init();
        $handles = [];

        foreach ($inboxes as $inbox) {
            $headers = $this->signature->sign($inbox, $body);
            $headers[] = 'Content-Length: ' . strlen($body);

            $ch = curl_init($inbox);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_USERAGENT      => 'Fedibots/0.1.0',
            ]);

            curl_multi_add_handle($multiHandle, $ch);
            $handles[$inbox] = $ch;
        }

        // Execute all requests
        do {
            $status = curl_multi_exec($multiHandle, $active);
            if ($active) {
                curl_multi_select($multiHandle);
            }
        } while ($active && $status === CURLM_OK);

        // Collect results
        $success = 0;
        foreach ($handles as $inbox => $ch) {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpCode >= 200 && $httpCode < 300) {
                $success++;
            } else {
                $this->storage->log('delivery_error', "Failed delivery to {$inbox}", [
                    'http_code' => $httpCode,
                    'error' => curl_error($ch),
                ]);
            }
            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }

        curl_multi_close($multiHandle);
        return $success;
    }
}
