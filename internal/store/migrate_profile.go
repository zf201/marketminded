package store

import (
	"encoding/json"
	"strings"
)

func (q *Queries) MigrateSettingsToSourceURLs() error {
	projects, err := q.ListProjects()
	if err != nil {
		return err
	}

	for _, p := range projects {
		settings, err := q.AllProjectSettings(p.ID)
		if err != nil {
			continue
		}

		var urls []SourceURL

		if website := settings["company_website"]; website != "" {
			notes := settings["website_notes"]
			for _, u := range splitCommaURLs(website) {
				urls = append(urls, SourceURL{URL: u, Notes: notes})
			}
		}

		if pricing := settings["company_pricing"]; pricing != "" {
			notes := settings["pricing_notes"]
			for _, u := range splitCommaURLs(pricing) {
				urls = append(urls, SourceURL{URL: u, Notes: notes})
			}
		}

		if len(urls) == 0 {
			continue
		}

		urlsJSON, err := json.Marshal(urls)
		if err != nil {
			continue
		}

		q.UpsertProfileSection(p.ID, "product_and_positioning", "")
		q.db.Exec(
			`UPDATE profile_sections SET source_urls = ? WHERE project_id = ? AND section = 'product_and_positioning' AND source_urls = '[]'`,
			string(urlsJSON), p.ID,
		)

		q.db.Exec("DELETE FROM project_settings WHERE project_id = ? AND key IN ('company_website', 'website_notes', 'company_pricing', 'pricing_notes')", p.ID)
	}

	return nil
}

func splitCommaURLs(s string) []string {
	parts := strings.Split(s, ",")
	var urls []string
	for _, p := range parts {
		p = strings.TrimSpace(p)
		if p != "" {
			urls = append(urls, p)
		}
	}
	return urls
}
