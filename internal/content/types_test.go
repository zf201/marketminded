package content

import "testing"

func TestLookupType(t *testing.T) {
	ct, ok := LookupType("x", "thread")
	if !ok {
		t.Fatal("expected to find x_thread")
	}
	if ct.ToolName != "write_x_thread" {
		t.Errorf("expected write_x_thread, got %s", ct.ToolName)
	}
	if ct.DisplayName != "X Thread" {
		t.Errorf("expected X Thread, got %s", ct.DisplayName)
	}
}

func TestLookupTypeNotFound(t *testing.T) {
	_, ok := LookupType("nonexistent", "type")
	if ok {
		t.Error("expected not found")
	}
}

func TestRegistryHas12Types(t *testing.T) {
	if len(Registry) != 12 {
		t.Errorf("expected 12 types, got %d", len(Registry))
	}
}

func TestIsWriteTool(t *testing.T) {
	if !IsWriteTool("write_blog_post") {
		t.Error("expected write_blog_post to be a write tool")
	}
	if IsWriteTool("fetch_url") {
		t.Error("fetch_url should not be a write tool")
	}
}
