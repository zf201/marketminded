package store

import (
	"os"
	"testing"
)

func testDB(t *testing.T) *Queries {
	t.Helper()
	db, err := Open(":memory:", os.DirFS("../../migrations"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	return NewQueries(db)
}

func TestOpenAndMigrate(t *testing.T) {
	q := testDB(t)
	_ = q // just verify DB opens and migrates without error

	// Verify projects table exists by running a query
	var count int
	err := q.db.QueryRow("SELECT count(*) FROM projects").Scan(&count)
	if err != nil {
		t.Fatalf("projects table not created: %v", err)
	}
}
