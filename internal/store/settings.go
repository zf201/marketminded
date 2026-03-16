package store

func (q *Queries) GetSetting(key string) (string, error) {
	var value string
	err := q.db.QueryRow("SELECT value FROM settings WHERE key = ?", key).Scan(&value)
	return value, err
}

func (q *Queries) SetSetting(key, value string) error {
	_, err := q.db.Exec(
		"INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = ?",
		key, value, value,
	)
	return err
}

func (q *Queries) AllSettings() (map[string]string, error) {
	rows, err := q.db.Query("SELECT key, value FROM settings")
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
