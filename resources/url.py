#!/usr/bin/env python3
# check_urls_remove_404_with_final.py
# Legge un file di input (una URL per riga), controlla ogni URL:
# - estrae status_code, final_url (dopo redirect), title, meta description e class del body
# - crea un CSV contenente SOLO gli URL che NON restituiscono 404
# - crea cleaned_urls.txt con gli URL NON-404 (originali, una per riga)
#
# Nota: gli URL che danno eccezioni di rete avranno status_code = "ERROR" e vengono inclusi (non sono 404).
# Se vuoi che vengano esclusi anche gli ERROR, modifica il filtro in seguito.
#
# Uso:
# python3 check_urls_remove_404_with_final.py --input urls.txt --csv output.csv --cleaned cleaned_urls.txt

import argparse
import concurrent.futures
import csv
import sys
import time
from typing import List, Dict, Any

import requests
from bs4 import BeautifulSoup

REQUEST_TIMEOUT = 10
DEFAULT_WORKERS = 10
HEADERS = {
    "User-Agent": "Mozilla/5.0 (compatible; URLChecker/1.0; +https://example.com/)"
}


def read_input_file(path: str) -> List[str]:
    with open(path, "r", encoding="utf-8") as f:
        lines = [ln.strip() for ln in f if ln.strip()]
    return lines


def fetch(url: str) -> Dict[str, Any]:
    """Restituisce un dict con i campi: url, status, final_url, title, meta_description, body_classes, error"""
    r = {
        "url": url,
        "status": None,
        "final_url": "",
        "title": "",
        "meta_description": "",
        "body_classes": "",
        "error": ""
    }
    try:
        resp = requests.get(url, headers=HEADERS, timeout=REQUEST_TIMEOUT, allow_redirects=True)
        r["status"] = resp.status_code
        r["final_url"] = resp.url or ""
        content_type = resp.headers.get("Content-Type", "")

        # Se è HTML, proviamo a parsare
        if resp.content and "html" in content_type.lower():
            soup = BeautifulSoup(resp.content, "lxml")
            title_tag = soup.find("title")
            if title_tag and title_tag.string:
                r["title"] = title_tag.string.strip()
            # meta description (name="description" o property="og:description")
            meta = soup.find("meta", attrs={"name": lambda v: v and v.lower() == "description"})
            if meta and meta.get("content"):
                r["meta_description"] = meta.get("content").strip()
            else:
                meta = soup.find("meta", attrs={"property": lambda v: v and v.lower() == "og:description"})
                if meta and meta.get("content"):
                    r["meta_description"] = meta.get("content").strip()
            body = soup.find("body")
            if body:
                classes = body.get("class")
                if classes:
                    if isinstance(classes, (list, tuple)):
                        r["body_classes"] = " ".join(classes).strip()
                    else:
                        r["body_classes"] = str(classes).strip()
    except requests.exceptions.RequestException as e:
        r["status"] = "ERROR"
        r["error"] = str(e)
    return r


def write_csv(path: str, rows: List[Dict[str, Any]]) -> None:
    with open(path, "w", encoding="utf-8", newline="") as csvfile:
        writer = csv.writer(csvfile, quoting=csv.QUOTE_MINIMAL)
        writer.writerow(["url", "status_code", "final_url", "title", "meta_description", "body_classes", "error"])
        for r in rows:
            writer.writerow([
                r.get("url", ""),
                r.get("status", ""),
                r.get("final_url", ""),
                r.get("title", ""),
                r.get("meta_description", ""),
                r.get("body_classes", ""),
                r.get("error", "")
            ])


def write_cleaned(path: str, rows: List[Dict[str, Any]]) -> None:
    with open(path, "w", encoding="utf-8") as f:
        for r in rows:
            f.write(r.get("url", "") + "\n")


def main():
    parser = argparse.ArgumentParser(description="Controlla URL e rimuove i 404, estraendo meta informazioni.")
    parser.add_argument("--input", "-i", default="urls.txt", help="File input con una URL per riga")
    parser.add_argument("--csv", "-c", default="output.csv", help="CSV di output (solo NON-404)")
    parser.add_argument("--cleaned", "-o", default="cleaned_urls.txt", help="File con URL NON-404")
    parser.add_argument("--workers", "-w", type=int, default=DEFAULT_WORKERS, help="Numero di thread concorrenti")
    parser.add_argument("--timeout", "-t", type=int, default=REQUEST_TIMEOUT, help="Timeout richieste (s)")
    args = parser.parse_args()

    global REQUEST_TIMEOUT
    REQUEST_TIMEOUT = args.timeout

    try:
        urls = read_input_file(args.input)
    except FileNotFoundError:
        print(f"[ERRORE] File input non trovato: {args.input}", file=sys.stderr)
        sys.exit(1)

    if not urls:
        print("[INFO] Nessuna URL trovata nel file di input.")
        return

    print(f"[INFO] Controllo {len(urls)} URL con {args.workers} worker (timeout={REQUEST_TIMEOUT}s)...")
    results: List[Dict[str, Any]] = []

    with concurrent.futures.ThreadPoolExecutor(max_workers=args.workers) as executor:
        future_to_url = {executor.submit(fetch, u): u for u in urls}
        for future in concurrent.futures.as_completed(future_to_url):
            res = future.result()
            results.append(res)
            status = res["status"]
            if status == 404:
                print(f"[SKIP 404] {res['url']}")
            elif status == "ERROR":
                print(f"[ERROR] {res['url']} -> {res['error']}")
            else:
                title_snip = (res["title"][:60] + "...") if len(res["title"]) > 60 else res["title"]
                print(f"[OK {status}] {res['url']} -> final: {res['final_url']} (title: {title_snip})")

    # Filtra SOLO i risultati NON-404
    non_404 = [r for r in results if r["status"] != 404]

    write_csv(args.csv, non_404)
    write_cleaned(args.cleaned, non_404)

    print(f"\n[FINITO] CSV scritto su: {args.csv} (righe: {len(non_404)})")
    print(f"[FINITO] File cleaned scritto su: {args.cleaned}")
    print("[NOTE] Gli entry con status = ERROR sono inclusi (non sono 404).")

if __name__ == "__main__":
    start = time.time()
    main()
    print(f"[TIME] Eseguito in {time.time() - start:.2f}s")
