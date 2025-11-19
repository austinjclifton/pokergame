<?php
// backend/app/services/game/cards/HandEvaluator.php
// -----------------------------------------------------------------------------
// Texas Hold'em hand evaluator.
// Takes 7 cards (2 hole + 5 board) and returns best 5-card hand.
// -----------------------------------------------------------------------------

declare(strict_types=1);

final class HandEvaluator
{
    /**
     * Evaluate best 5-card hand from 7 cards.
     *
     * @param array<string> $cards  (e.g. ["AS", "KD", "QC"])
     * @return array{
     *   rank_value:int,
     *   hand_name:string,
     *   best_hand:array<string>
     * }
     */
    public function evaluate(array $cards): array
    {
        if (count($cards) < 5) {
            throw new InvalidArgumentException('Need at least 5 cards to evaluate');
        }

        $parsed = [];
        foreach ($cards as $card) {
            $parsed[] = $this->parseCard($card);
        }

        $bestRankValue = 0;
        $bestHandName  = 'High Card';
        $bestHandCards = [];

        $combinations = $this->getCombinations($parsed, 5);
        foreach ($combinations as $combo) {
            $result = $this->evaluateFiveCards($combo);
            if ($result['rank_value'] > $bestRankValue) {
                $bestRankValue = $result['rank_value'];
                $bestHandName  = $result['hand_name'];
                $bestHandCards = $result['cards'];
            }
        }

        return [
            'rank_value' => $bestRankValue,
            'hand_name'  => $bestHandName,
            'best_hand'  => $bestHandCards,
        ];
    }

    /**
     * Compatibility wrapper for WinnerCalculator
     *
     * WinnerCalculator calls:
     *   evaluateBestHand($holeCards, $board)
     *
     * This wrapper now converts that into:
     *   evaluate($all7cards)
     *
     * @param string[] $hole  (2 cards)
     * @param string[] $board (0–5)
     * @return array{
     *   rank:int,
     *   name:string,
     *   cards:array<string>
     * }
     */
    public function evaluateBestHand(array $hole, array $board): array
    {
        $cards  = array_merge($hole, $board);
        $result = $this->evaluate($cards);

        return [
            'rank'  => $result['rank_value'],   // WinnerCalculator expects numeric rank
            'name'  => $result['hand_name'],
            'cards' => $result['best_hand'],
        ];
    }

    private function parseCard(string $card): array
    {
        $suit = substr($card, -1);
        $rankStr = substr($card, 0, -1);

        $rankMap = [
            'A' => 14, 'K' => 13, 'Q' => 12, 'J' => 11, 'T' => 10,
            '9' => 9,  '8' => 8,  '7' => 7,  '6' => 6,
            '5' => 5,  '4' => 4,  '3' => 3,  '2' => 2,
        ];

        $rank = $rankMap[$rankStr] ?? (int)$rankStr;

        return ['rank' => $rank, 'suit' => $suit, 'original' => $card];
    }

    private function evaluateFiveCards(array $cards): array
    {
        usort($cards, fn($a, $b) => $b['rank'] <=> $a['rank']);

        $ranks          = array_column($cards, 'rank');
        $suits          = array_column($cards, 'suit');
        $originalCards  = array_column($cards, 'original');
        $rankCounts     = $this->groupByRank($ranks);
        $isFlush        = $this->isFlush($suits);
        $straightInfo   = $this->isStraight($ranks);

        // Royal Flush
        if ($isFlush && $straightInfo['is'] &&
            $ranks === [14, 13, 12, 11, 10]) {
            return [
                'rank_value' => $this->calculateRankValue(1, [14,13,12,11,10]),
                'hand_name'  => 'Royal Flush',
                'cards'      => $originalCards,
            ];
        }

        // Straight Flush
        if ($isFlush && $straightInfo['is']) {
            $high = $straightInfo['high'];
            return [
                'rank_value' => $this->calculateRankValue(2, [$high]),
                'hand_name'  => "Straight Flush, {$this->rankToString($high)} high",
                'cards'      => $originalCards,
            ];
        }

        // Four of a Kind
        if (in_array(4, $rankCounts, true)) {
            $quad    = array_search(4, $rankCounts);
            $kicker  = array_search(1, $rankCounts);
            return [
                'rank_value' => $this->calculateRankValue(3, [(int)$quad,(int)$kicker]),
                'hand_name'  => "Four {$this->rankToString((int)$quad)}s",
                'cards'      => $originalCards,
            ];
        }

        // Full House
        if (in_array(3, $rankCounts, true) && in_array(2, $rankCounts, true)) {
            $trips = array_search(3, $rankCounts);
            $pair  = array_search(2, $rankCounts);
            return [
                'rank_value' => $this->calculateRankValue(4, [(int)$trips,(int)$pair]),
                'hand_name'  => "{$this->rankToString((int)$trips)}s full of {$this->rankToString((int)$pair)}s",
                'cards'      => $originalCards,
            ];
        }

        // Flush
        if ($isFlush) {
            return [
                'rank_value' => $this->calculateRankValue(5, $ranks),
                'hand_name'  => "Flush, {$this->rankToString($ranks[0])} high",
                'cards'      => $originalCards,
            ];
        }

        // Straight
        if ($straightInfo['is']) {
            $high = $straightInfo['high'];
            return [
                'rank_value' => $this->calculateRankValue(6, [$high]),
                'hand_name'  => "Straight, {$this->rankToString($high)} high",
                'cards'      => $originalCards,
            ];
        }

        // Trips
        if (in_array(3, $rankCounts, true)) {
            $trips   = array_search(3, $rankCounts);
            $kickers = array_keys(array_filter($rankCounts, fn($c)=>$c===1));
            rsort($kickers);
            return [
                'rank_value' => $this->calculateRankValue(7, [(int)$trips,$kickers[0],$kickers[1]]),
                'hand_name'  => "Three {$this->rankToString((int)$trips)}s",
                'cards'      => $originalCards,
            ];
        }

        // Two Pair
        $pairs = array_keys(array_filter($rankCounts, fn($c)=>$c===2));
        if (count($pairs) === 2) {
            rsort($pairs);
            $kicker = array_search(1, $rankCounts);
            return [
                'rank_value' => $this->calculateRankValue(8, [(int)$pairs[0],(int)$pairs[1],(int)$kicker]),
                'hand_name'  => "{$this->rankToString((int)$pairs[0])}s and {$this->rankToString((int)$pairs[1])}s",
                'cards'      => $originalCards,
            ];
        }

        // One Pair
        if (count($pairs) === 1) {
            $pair    = $pairs[0];
            $kickers = array_keys(array_filter($rankCounts, fn($c)=>$c===1));
            rsort($kickers);
            return [
                'rank_value' => $this->calculateRankValue(9, [(int)$pair,$kickers[0],$kickers[1],$kickers[2]]),
                'hand_name'  => "Pair of {$this->rankToString((int)$pair)}s",
                'cards'      => $originalCards,
            ];
        }

        // High Card
        return [
            'rank_value' => $this->calculateRankValue(10, $ranks),
            'hand_name'  => "{$this->rankToString($ranks[0])} high",
            'cards'      => $originalCards,
        ];
    }

    private function calculateRankValue(int $handType, array $values): int
    {
        $inverted = 11 - $handType;
        $value    = $inverted;

        foreach ($values as $v) {
            $value = $value * 15 + $v;
        }
        return $value;
    }

    private function isFlush(array $suits): bool
    {
        return count(array_unique($suits)) === 1;
    }

    private function groupByRank(array $ranks): array
    {
        return array_count_values($ranks);
    }

    private function isStraight(array $ranks): array
    {
        $u = array_unique($ranks);
        if (count($u) !== 5) return ['is'=>false,'high'=>0];

        sort($u);

        // Regular straight
        $reg = true;
        for ($i=1;$i<5;$i++) {
            if ($u[$i] !== $u[$i-1] + 1) {
                $reg = false;
                break;
            }
        }
        if ($reg) {
            return ['is'=>true,'high'=>max($u)];
        }

        // Wheel (A-2-3-4-5)
        if ($u === [2,3,4,5,14]) {
            return ['is'=>true,'high'=>5];
        }

        return ['is'=>false,'high'=>0];
    }

    private function rankToString(int $rank): string
    {
        return [
            14=>'Ace', 13=>'King', 12=>'Queen', 11=>'Jack', 10=>'Ten'
        ][$rank] ?? (string)$rank;
    }

    private function getCombinations(array $array, int $k): array
    {
        if ($k === 0) return [[]];
        if (empty($array)) return [];

        $head = $array[0];
        $tail = array_slice($array,1);

        $with = $this->getCombinations($tail, $k-1);
        foreach ($with as &$combo) {
            array_unshift($combo, $head);
        }

        $without = $this->getCombinations($tail, $k);
        return array_merge($with, $without);
    }
}
