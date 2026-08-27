#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -ne 1 ]; then
  echo "Usage: $0 <health-url>" >&2
  exit 2
fi

health_url=$1
maximum_attempts=3
retry_delay_seconds=5
response_body_file=$(mktemp)
trap 'rm -f "${response_body_file}"' EXIT

print_response_body() {
  echo '!!! Response body:' >&2
  if [ -s "${response_body_file}" ]; then
    sed 's/^/!!!   /' "${response_body_file}" >&2
    return
  fi

  echo '!!!   <empty>' >&2
}

attempt=1
while [ "${attempt}" -le "${maximum_attempts}" ]; do
  : > "${response_body_file}"
  curl_exit_status=0
  status_code=$(curl -sS -o "${response_body_file}" -w '%{http_code}' --max-time 30 \
    -H 'Authorization: Bearer deliberately-invalid' \
    "${health_url}") || curl_exit_status=$?

  if [ "${curl_exit_status}" -ne 0 ]; then
    echo "!!! Authorization probe curl failed with exit code ${curl_exit_status}." >&2
    print_response_body
    exit 1
  fi

  case "${status_code}" in
    401)
      echo '==> Authorization header reaches PHP'
      exit 0
      ;;
    200)
      echo '!!! /api/health answered 200 to an invalid bearer token, expected 401.' >&2
      echo '!!! The Authorization header is not reaching PHP, so every request after' >&2
      echo '!!! a successful login will 401 and the app will return users to the' >&2
      echo '!!! sign-in form. Check the HTTP_AUTHORIZATION rewrite in public/.htaccess.' >&2
      print_response_body
      exit 1
      ;;
    500)
      if [ "${attempt}" -lt "${maximum_attempts}" ]; then
        echo "==> Authorization probe answered 500; retrying in ${retry_delay_seconds} seconds" >&2
        sleep "${retry_delay_seconds}"
        attempt=$((attempt + 1))
        continue
      fi

      echo '!!! /api/health still answered 500 after the cold-cache grace period.' >&2
      echo '!!! The release threw while it processed the Authorization header.' >&2
      echo '!!! Check shared/var/log/prod-*.log for the cause.' >&2
      print_response_body
      exit 1
      ;;
    *)
      echo "!!! Authorization probe received unexpected HTTP ${status_code}." >&2
      print_response_body
      exit 1
      ;;
  esac
done
