<?php

namespace EasyBusyChats\Service;

use EasyBusyChats\Exception\IntegrationException;

final class PhoneContactMatcher
{
    private const SEARCH_LIMIT = 50;
    private const RESPONSE_LIMIT = 20;

    public function __construct(private readonly CrmService $crm)
    {
    }

    public function find(string $rawPhone): array
    {
        $phone = PhoneNormalizer::normalize($rawPhone);
        if (!PhoneNormalizer::isValid($phone)) {
            throw new IntegrationException(
                'EasyBusy returned an invalid phone number.',
                'PHONE_INVALID',
                false,
                ['minimumDigits' => PhoneNormalizer::MIN_MATCH_DIGITS]
            );
        }

        $exactIds = $this->crm->findContactIdsByExactPhone($phone, self::SEARCH_LIMIT + 1);
        $exact = $this->filterMatches($phone, $exactIds, true);
        if ($exact !== []) {
            return $this->result($phone, 'exact', strlen($phone), $exact, count($exactIds) > self::SEARCH_LIMIT);
        }

        $tailIds = $this->crm->findContactIdsByPhoneTail($phone, self::SEARCH_LIMIT + 1);
        $tailMatches = $this->filterMatches($phone, $tailIds, false);
        if ($tailMatches === []) {
            return [
                'phone' => $phone,
                'matchType' => null,
                'matchLength' => 0,
                'candidates' => [],
                'truncated' => count($tailIds) > self::SEARCH_LIMIT,
            ];
        }

        $bestLength = max(array_column($tailMatches, '_matchLength'));
        $best = array_values(array_filter(
            $tailMatches,
            static fn(array $item): bool => $item['_matchLength'] === $bestLength
        ));

        return $this->result(
            $phone,
            'tail',
            $bestLength,
            $best,
            count($tailIds) > self::SEARCH_LIMIT
        );
    }

    private function filterMatches(string $phone, array $contactIds, bool $exactOnly): array
    {
        $matches = [];
        foreach ($this->crm->getReadableContacts($contactIds) as $contact) {
            $bestLength = 0;
            foreach ($contact['phones'] as $candidatePhone) {
                $candidate = PhoneNormalizer::normalize($candidatePhone);
                if ($candidate === '') {
                    continue;
                }
                if ($exactOnly && $candidate === $phone) {
                    $bestLength = strlen($phone);
                    break;
                }
                if (!$exactOnly) {
                    $bestLength = max($bestLength, PhoneNormalizer::longestCommonSuffix($phone, $candidate));
                }
            }
            if ($bestLength < ($exactOnly ? strlen($phone) : PhoneNormalizer::MIN_MATCH_DIGITS)) {
                continue;
            }
            $id = (int)$contact['id'];
            $matches[$id] = [
                'id' => $id,
                'name' => (string)$contact['name'],
                'phones' => array_values(array_map('strval', $contact['phones'])),
                '_matchLength' => $bestLength,
            ];
        }

        return array_values($matches);
    }

    private function result(
        string $phone,
        string $matchType,
        int $matchLength,
        array $candidates,
        bool $truncated
    ): array {
        $candidates = array_slice($candidates, 0, self::RESPONSE_LIMIT);
        foreach ($candidates as &$candidate) {
            unset($candidate['_matchLength']);
        }
        unset($candidate);

        return [
            'phone' => $phone,
            'matchType' => $matchType,
            'matchLength' => $matchLength,
            'candidates' => $candidates,
            'truncated' => $truncated,
        ];
    }
}
