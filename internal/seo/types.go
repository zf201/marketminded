package seo

// --- Clean output types (what tools return to the agent) ---

type KeywordMetric struct {
	Keyword          string  `json:"keyword"`
	SearchVolume     int     `json:"search_volume"`
	CPC              float64 `json:"cpc"`
	Competition      string  `json:"competition"`       // HIGH, MEDIUM, LOW
	CompetitionIndex float64 `json:"competition_index"` // 0-100
}

type KeywordSuggestion struct {
	Keyword           string  `json:"keyword"`
	SearchVolume      int     `json:"search_volume"`
	KeywordDifficulty float64 `json:"keyword_difficulty"` // 0-100
	CPC               float64 `json:"cpc"`
	Competition       string  `json:"competition"`
}

type RankedKeyword struct {
	Keyword           string  `json:"keyword"`
	Position          int     `json:"position"`
	SearchVolume      int     `json:"search_volume"`
	KeywordDifficulty float64 `json:"keyword_difficulty"`
	CPC               float64 `json:"cpc"`
	URL               string  `json:"url"`
}

// --- DataForSEO API request/response wrappers ---

// Shared envelope for all DataForSEO responses
type apiResponse struct {
	StatusCode    int       `json:"status_code"`
	StatusMessage string    `json:"status_message"`
	Tasks         []apiTask `json:"tasks"`
}

type apiTask struct {
	StatusCode    int             `json:"status_code"`
	StatusMessage string          `json:"status_message"`
	Result        []apiTaskResult `json:"result"`
}

type apiTaskResult struct {
	Items []apiItem `json:"items"`
}

// Search Volume endpoint items
type apiItem struct {
	// search_volume/live fields
	Keyword          string          `json:"keyword,omitempty"`
	SearchVolume     int             `json:"search_volume"`
	CPC              float64         `json:"cpc"`
	Competition      *float64        `json:"competition,omitempty"`
	CompetitionLevel string          `json:"competition_level,omitempty"`
	CompetitionIndex *float64        `json:"competition_index,omitempty"`
	// related_keywords/live + keyword_suggestions/live fields
	KeywordData *apiKeywordData `json:"keyword_data,omitempty"`
	// ranked_keywords/live fields
	RankedSerpElement *apiRankedSERP `json:"ranked_serp_element,omitempty"`
}

type apiKeywordData struct {
	Keyword           string         `json:"keyword"`
	KeywordInfo       apiKeywordInfo `json:"keyword_info"`
	KeywordProperties apiKeywordProps `json:"keyword_properties"`
}

type apiKeywordInfo struct {
	SearchVolume      int     `json:"search_volume"`
	CPC               float64 `json:"cpc"`
	Competition       float64 `json:"competition"`
	CompetitionLevel  string  `json:"competition_level"`
	KeywordDifficulty float64 `json:"keyword_difficulty"`
}

type apiKeywordProps struct {
	KeywordDifficulty float64 `json:"keyword_difficulty"`
}

type apiRankedSERP struct {
	SERPItem apiSERPItem `json:"serp_item"`
}

type apiSERPItem struct {
	Type         string          `json:"type"`
	RankGroup    int             `json:"rank_group"`
	RankAbsolute int             `json:"rank_absolute"`
	Position     string          `json:"position"`
	URL          string          `json:"url"`
	KeywordData  *apiKeywordData `json:"keyword_data,omitempty"`
}
