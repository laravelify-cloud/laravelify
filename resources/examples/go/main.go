package main

import (
	"fmt"
	"net/http"
	"os"
)

func main() {
	http.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		fmt.Fprint(w, "Go works")
	})
	http.ListenAndServe("127.0.0.1:"+os.Getenv("PORT"), nil)
}
