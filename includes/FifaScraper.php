<?php
class FifaScraper {
    private function fetchUrl($url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $html = curl_exec($ch);
        curl_close($ch);
        return $html;
    }

    public function getLatestRankings() {
        // Step 1: Fetch the main page to extract the latest dateId
        $mainPageUrl = "https://inside.fifa.com/fifa-world-ranking/men";
        $html = $this->fetchUrl($mainPageUrl);
        
        if (!$html) {
            return ['status' => 'error', 'message' => 'Failed to fetch FIFA ranking page'];
        }

        // Extract dateId from the HTML (format: id14233, id14234, etc.)
        if (preg_match('/id(\d{4,6})/', $html, $matches)) {
            $dateId = 'id' . $matches[1];
        } else {
            return ['status' => 'error', 'message' => 'Could not extract dateId from FIFA page'];
        }

        // Step 2: Fetch the JSON data using the extracted dateId
        $apiUrl = "https://inside.fifa.com/api/ranking-overview?locale=en&dateId={$dateId}";
        $jsonData = $this->fetchUrl($apiUrl);
        
        if (!$jsonData) {
            return ['status' => 'error', 'message' => 'Failed to fetch FIFA ranking data'];
        }

        $data = json_decode($jsonData, true);
        
        if (!$data || !isset($data['rankings']) || empty($data['rankings'])) {
            return ['status' => 'error', 'message' => 'Invalid or empty ranking data'];
        }

        // Step 3: Parse and format the rankings
        $rankings = [];
        foreach ($data['rankings'] as $item) {
            $rankingItem = $item['rankingItem'] ?? null;
            if (!$rankingItem) continue;

            // Skip countries without a valid rank (e.g., Eritrea has rank = null)
            if (!isset($rankingItem['rank']) || $rankingItem['rank'] === null) {
                continue;
            }

            $rankings[] = [
                'rank' => $rankingItem['rank'],
                'country_name' => $rankingItem['name'] ?? '',
                'country_code' => $rankingItem['countryCode'] ?? null,
                'points' => $rankingItem['totalPoints'] ?? 0,
                'previous_points' => $item['previousPoints'] ?? 0,
                'rank_change' => ($rankingItem['rank'] ?? 0) - ($rankingItem['previousRank'] ?? 0),
                'flag_url' => $rankingItem['flag']['src'] ?? null,
                'confederation' => $item['tag']['text'] ?? null
            ];
        }

        return [
            'status' => 'success',
            'rankings' => $rankings,
            'total' => count($rankings)
        ];
    }
}
