package store

import (
	"fmt"
	"time"
)

type BrainstormChat struct {
	ID             int64
	ProjectID      int64
	Title          string
	Section        string
	ContentPieceID *int64
	CreatedAt      time.Time
}

type BrainstormMessage struct {
	ID        int64
	ChatID    int64
	Role      string
	Content   string
	CreatedAt time.Time
}

func (q *Queries) CreateBrainstormChat(projectID int64, title, section string, contentPieceID *int64) (*BrainstormChat, error) {
	var sectionVal any
	if section != "" {
		sectionVal = section
	}
	res, err := q.db.Exec("INSERT INTO brainstorm_chats (project_id, title, section, content_piece_id) VALUES (?, ?, ?, ?)", projectID, title, sectionVal, contentPieceID)
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	c := &BrainstormChat{}
	err = q.db.QueryRow("SELECT id, project_id, COALESCE(title,''), COALESCE(section,''), content_piece_id, created_at FROM brainstorm_chats WHERE id = ?", id).
		Scan(&c.ID, &c.ProjectID, &c.Title, &c.Section, &c.ContentPieceID, &c.CreatedAt)
	return c, err
}

func (q *Queries) GetBrainstormChat(id int64) (*BrainstormChat, error) {
	c := &BrainstormChat{}
	err := q.db.QueryRow("SELECT id, project_id, COALESCE(title,''), COALESCE(section,''), content_piece_id, created_at FROM brainstorm_chats WHERE id = ?", id).
		Scan(&c.ID, &c.ProjectID, &c.Title, &c.Section, &c.ContentPieceID, &c.CreatedAt)
	return c, err
}

func (q *Queries) ListBrainstormChats(projectID int64) ([]BrainstormChat, error) {
	rows, err := q.db.Query("SELECT id, project_id, COALESCE(title,''), COALESCE(section,''), content_piece_id, created_at FROM brainstorm_chats WHERE project_id = ? ORDER BY created_at DESC", projectID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var chats []BrainstormChat
	for rows.Next() {
		var c BrainstormChat
		if err := rows.Scan(&c.ID, &c.ProjectID, &c.Title, &c.Section, &c.ContentPieceID, &c.CreatedAt); err != nil {
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

func (q *Queries) GetOrCreateProfileChat(projectID int64) (*BrainstormChat, error) {
	c := &BrainstormChat{}
	err := q.db.QueryRow(
		"SELECT id, project_id, COALESCE(title,''), COALESCE(section,''), content_piece_id, created_at FROM brainstorm_chats WHERE project_id = ? AND section = 'profile'",
		projectID,
	).Scan(&c.ID, &c.ProjectID, &c.Title, &c.Section, &c.ContentPieceID, &c.CreatedAt)
	if err == nil {
		return c, nil
	}
	// Not found, create it
	return q.CreateBrainstormChat(projectID, "Profile Builder", "profile", nil)
}

func (q *Queries) GetOrCreateContextChat(projectID, contextItemID int64) (*BrainstormChat, error) {
	c := &BrainstormChat{}
	err := q.db.QueryRow(
		"SELECT id, project_id, COALESCE(title,''), COALESCE(section,''), content_piece_id, created_at FROM brainstorm_chats WHERE project_id = ? AND section = ?",
		projectID, fmt.Sprintf("context_%d", contextItemID),
	).Scan(&c.ID, &c.ProjectID, &c.Title, &c.Section, &c.ContentPieceID, &c.CreatedAt)
	if err == nil {
		return c, nil
	}
	return q.CreateBrainstormChat(projectID, "Context Item", fmt.Sprintf("context_%d", contextItemID), nil)
}

func (q *Queries) GetOrCreateSectionChat(projectID int64, section string) (*BrainstormChat, error) {
	c := &BrainstormChat{}
	err := q.db.QueryRow(
		"SELECT id, project_id, COALESCE(title,''), COALESCE(section,''), content_piece_id, created_at FROM brainstorm_chats WHERE project_id = ? AND section = ?",
		projectID, section,
	).Scan(&c.ID, &c.ProjectID, &c.Title, &c.Section, &c.ContentPieceID, &c.CreatedAt)
	if err == nil {
		return c, nil
	}
	return q.CreateBrainstormChat(projectID, sectionChatTitle(section), section, nil)
}

func sectionChatTitle(section string) string {
	return "Profile: " + section
}

func (q *Queries) GetOrCreatePieceChat(projectID, pieceID int64) (*BrainstormChat, error) {
	c := &BrainstormChat{}
	err := q.db.QueryRow(
		"SELECT id, project_id, COALESCE(title,''), COALESCE(section,''), content_piece_id, created_at FROM brainstorm_chats WHERE project_id = ? AND content_piece_id = ?",
		projectID, pieceID,
	).Scan(&c.ID, &c.ProjectID, &c.Title, &c.Section, &c.ContentPieceID, &c.CreatedAt)
	if err == nil {
		return c, nil
	}
	return q.CreateBrainstormChat(projectID, "Improve Piece", "", &pieceID)
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
