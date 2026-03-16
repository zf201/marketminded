.PHONY: generate build run test clean

generate:
	templ generate ./web/templates/

build: generate
	go build -o server ./cmd/server/

run: build
	./server

test:
	go test ./...

clean:
	rm -f server marketminded
