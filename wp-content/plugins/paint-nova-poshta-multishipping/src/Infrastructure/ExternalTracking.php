<?php

namespace Paint\NovaPoshta\Infrastructure;

defined('ABSPATH') || exit;

final class ExternalTracking
{
    /** Keep only public status, never the recipient, telephone or COD data. */
    public function check(string $number): array
    {
        $result = (new ApiClient())->call('TrackingDocument', 'getStatusDocuments', [
            'Documents' => [['DocumentNumber' => $number]],
        ]);
        $status = ['checked_at' => time(), 'code' => '', 'text' => '', 'available' => false, 'rejected' => false];
        if (is_wp_error($result)) { return $status; }
        if (empty($result['success'])) {
            // Official API validation response verified with a synthetic invalid number.
            $status['rejected'] = in_array('20001401442', array_map('strval', (array) ($result['errorCodes'] ?? [])), true);
            if ($status['rejected']) {
                $status['text'] = __('Nova Poshta does not show this TTN as awaiting dispatch. Check it with the customer.', 'paint-nova-poshta-multishipping');
            }
            return $status;
        }
        foreach ((array) ($result['data'] ?? []) as $row) {
            if (!is_array($row) || (string) ($row['Number'] ?? '') !== $number || !isset($row['StatusCode'])) { continue; }
            $status['code'] = sanitize_text_field((string) $row['StatusCode']);
            $status['text'] = sanitize_text_field((string) ($row['Status'] ?? ''));
            $status['available'] = true;
            break;
        }
        return $status;
    }
}
