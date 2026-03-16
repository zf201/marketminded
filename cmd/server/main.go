package main

import (
	"fmt"
	"log"
	"net/http"
	"os"
)

func main() {
	port := os.Getenv("MARKETMINDED_PORT")
	if port == "" {
		port = "8080"
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		fmt.Fprintf(w, "MarketMinded running")
	})

	log.Printf("Starting on :%s", port)
	log.Fatal(http.ListenAndServe(":"+port, mux))
}
