package sse

import (
	"encoding/json"
	"fmt"
	"net/http"
)

// Stream provides Server-Sent Events over an http.ResponseWriter.
type Stream struct {
	w       http.ResponseWriter
	flusher http.Flusher
}

// New creates a Stream, setting the required SSE headers.
// Returns an error if the ResponseWriter does not support flushing.
func New(w http.ResponseWriter) (*Stream, error) {
	flusher, ok := w.(http.Flusher)
	if !ok {
		return nil, fmt.Errorf("streaming not supported")
	}

	w.Header().Set("Content-Type", "text/event-stream")
	w.Header().Set("Cache-Control", "no-cache")
	w.Header().Set("Connection", "keep-alive")

	return &Stream{w: w, flusher: flusher}, nil
}

// Send writes a named event with a string data payload.
func (s *Stream) Send(event, data string) {
	fmt.Fprintf(s.w, "event: %s\ndata: %s\n\n", event, data)
	s.flusher.Flush()
}

// SendJSON writes a named event with a JSON-encoded data payload.
func (s *Stream) SendJSON(event string, v any) {
	data, _ := json.Marshal(v)
	fmt.Fprintf(s.w, "event: %s\ndata: %s\n\n", event, string(data))
	s.flusher.Flush()
}

// SendData writes an unnamed event (just "data:" line), matching the
// current pipeline handler pattern where the frontend parses the type from JSON.
func (s *Stream) SendData(v any) {
	data, _ := json.Marshal(v)
	fmt.Fprintf(s.w, "data: %s\n\n", string(data))
	s.flusher.Flush()
}

// Close is a no-op placeholder for future cleanup.
func (s *Stream) Close() {}
