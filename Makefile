.PHONY: generate build run dev test clean reset

generate:
	templ generate ./web/templates/

build: generate
	go build -o server ./cmd/server/

run: build
	./server

dev: build
	@echo "Starting MarketMinded on :8080..."
	@./server

test:
	go test ./...

clean:
	rm -f server marketminded

reset: clean
	rm -f marketminded.db
	@echo "DB reset. Run 'make dev' to start fresh."
