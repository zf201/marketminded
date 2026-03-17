package store

func (q *Queries) GetProjectSetting(projectID int64, key string) (string, error) {
	var value string
	err := q.db.QueryRow("SELECT value FROM project_settings WHERE project_id = ? AND key = ?", projectID, key).Scan(&value)
	return value, err
}

func (q *Queries) SetProjectSetting(projectID int64, key, value string) error {
	_, err := q.db.Exec(
		"INSERT INTO project_settings (project_id, key, value) VALUES (?, ?, ?) ON CONFLICT(project_id, key) DO UPDATE SET value = ?",
		projectID, key, value, value,
	)
	return err
}

func (q *Queries) AllProjectSettings(projectID int64) (map[string]string, error) {
	rows, err := q.db.Query("SELECT key, value FROM project_settings WHERE project_id = ?", projectID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	settings := make(map[string]string)
	for rows.Next() {
		var k, v string
		if err := rows.Scan(&k, &v); err != nil {
			return nil, err
		}
		settings[k] = v
	}
	return settings, rows.Err()
}
