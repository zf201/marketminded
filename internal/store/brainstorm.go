package store

import "time"

type BrainstormChat struct {
	ID        int64
	ProjectID int64
	Title     string
	CreatedAt time.Time
}

type BrainstormMessage struct {
	ID        int64
	ChatID    int64
	Role      string
	Content   string
	CreatedAt time.Time
}

func (q *Queries) CreateBrainstormChat(projectID int64, title string) (*BrainstormChat, error) {
	res, err := q.db.Exec("INSERT INTO brainstorm_chats (project_id, title) VALUES (?, ?)", projectID, title)
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	c := &BrainstormChat{}
	err = q.db.QueryRow("SELECT id, project_id, COALESCE(title,''), created_at FROM brainstorm_chats WHERE id = ?", id).
		Scan(&c.ID, &c.ProjectID, &c.Title, &c.CreatedAt)
	return c, err
}

func (q *Queries) GetBrainstormChat(id int64) (*BrainstormChat, error) {
	c := &BrainstormChat{}
	err := q.db.QueryRow("SELECT id, project_id, COALESCE(title,''), created_at FROM brainstorm_chats WHERE id = ?", id).
		Scan(&c.ID, &c.ProjectID, &c.Title, &c.CreatedAt)
	return c, err
}

func (q *Queries) ListBrainstormChats(projectID int64) ([]BrainstormChat, error) {
	rows, err := q.db.Query("SELECT id, project_id, COALESCE(title,''), created_at FROM brainstorm_chats WHERE project_id = ? ORDER BY created_at DESC", projectID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var chats []BrainstormChat
	for rows.Next() {
		var c BrainstormChat
		if err := rows.Scan(&c.ID, &c.ProjectID, &c.Title, &c.CreatedAt); err != nil {
			return nil, err
		}
		chats = append(chats, c)
	}
	return chats, rows.Err()
}

func (q *Queries) AddBrainstormMessage(chatID int64, role, content string) (*BrainstormMessage, error) {
	res, err := q.db.Exec("INSERT INTO brainstorm_messages (chat_id, role, content) VALUES (?, ?, ?)", chatID, role, content)
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	m := &BrainstormMessage{}
	err = q.db.QueryRow("SELECT id, chat_id, role, content, created_at FROM brainstorm_messages WHERE id = ?", id).
		Scan(&m.ID, &m.ChatID, &m.Role, &m.Content, &m.CreatedAt)
	return m, err
}

func (q *Queries) ListBrainstormMessages(chatID int64) ([]BrainstormMessage, error) {
	rows, err := q.db.Query("SELECT id, chat_id, role, content, created_at FROM brainstorm_messages WHERE chat_id = ? ORDER BY created_at ASC", chatID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var msgs []BrainstormMessage
	for rows.Next() {
		var m BrainstormMessage
		if err := rows.Scan(&m.ID, &m.ChatID, &m.Role, &m.Content, &m.CreatedAt); err != nil {
			return nil, err
		}
		msgs = append(msgs, m)
	}
	return msgs, rows.Err()
}
