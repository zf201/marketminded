include .env
export

.PHONY: generate build run dev start restart test clean reset css

css:
	npx tailwindcss -i web/static/input.css -o web/static/output.css

generate: css
	~/go/bin/templ generate ./web/templates/

build: generate
	go build -o server ./cmd/server/

run: build
	./server

dev: build
	@echo "Starting MarketMinded on :8080..."
	@./server

start: build
	@pkill -f './server' 2>/dev/null || true
	@sleep 1
	@echo "Starting MarketMinded on :8080..."
	@./server &

restart: build
	@pkill -f './server' 2>/dev/null || true
	@sleep 1
	@echo "Restarting MarketMinded on :8080..."
	@./server &

test:
	go test ./...

clean:
	rm -f server marketminded

reset: clean
	rm -f marketminded.db
	@echo "DB reset. Run 'make start' to start fresh."
