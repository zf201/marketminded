package store

import "testing"

func TestBrainstormChatFlow(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	chat, err := q.CreateBrainstormChat(p.ID, "Ideas for blog", "")
	if err != nil {
		t.Fatalf("create chat: %v", err)
	}

	_, err = q.AddBrainstormMessage(chat.ID, "user", "What about AI trends?")
	if err != nil {
		t.Fatalf("add message: %v", err)
	}
	_, err = q.AddBrainstormMessage(chat.ID, "assistant", "Great idea! Here are some angles...")
	if err != nil {
		t.Fatalf("add message: %v", err)
	}

	msgs, _ := q.ListBrainstormMessages(chat.ID)
	if len(msgs) != 2 {
		t.Errorf("expected 2 messages, got %d", len(msgs))
	}
	if msgs[0].Role != "user" {
		t.Errorf("expected user first, got %s", msgs[0].Role)
	}
}
