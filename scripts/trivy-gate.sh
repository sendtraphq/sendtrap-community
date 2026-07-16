#!/usr/bin/env bash
set -euo pipefail

report="${1:-trivy-results.json}"

if [[ ! -s "$report" ]]; then
    echo "::error title=Trivy report missing::Expected a non-empty report at $report"
    exit 1
fi

count="$(jq '[.Results[]?.Vulnerabilities[]?] | length' "$report")"

if [[ "$count" -eq 0 ]]; then
    echo "Trivy gate passed: no fixable HIGH/CRITICAL vulnerabilities"
    if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
        echo "### Trivy gate: passed" >> "$GITHUB_STEP_SUMMARY"
        echo "No fixable HIGH/CRITICAL vulnerabilities." >> "$GITHUB_STEP_SUMMARY"
    fi
    exit 0
fi

echo "Trivy gate failed: $count fixable HIGH/CRITICAL vulnerability finding(s)"

jq -r '
    .Results[] as $result
    | $result.Vulnerabilities[]?
    | [
        .VulnerabilityID,
        .Severity,
        .PkgName,
        .InstalledVersion,
        .FixedVersion,
        $result.Target
      ]
    | @tsv
' "$report" |
while IFS=$'\t' read -r id severity package installed fixed target; do
    message="$package $installed -> $fixed in $target"
    message="${message//'%'/'%25'}"
    message="${message//$'\r'/'%0D'}"
    message="${message//$'\n'/'%0A'}"
    echo "::error title=Trivy $severity $id::$message"
done

if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
    {
        echo "### Trivy gate: failed"
        echo
        echo "$count fixable HIGH/CRITICAL vulnerability finding(s):"
        echo
        echo "| Severity | Vulnerability | Package | Installed | Fixed | Target |"
        echo "|---|---|---|---|---|---|"
        jq -r '
            .Results[] as $result
            | $result.Vulnerabilities[]?
            | "| \(.Severity) | \(.VulnerabilityID) | \(.PkgName) | \(.InstalledVersion) | \(.FixedVersion) | \($result.Target) |"
        ' "$report"
    } >> "$GITHUB_STEP_SUMMARY"
fi

exit 1
