#!/usr/bin/env bash
# Builds build/ruvelo-help-center.zip: only what runs on a site, with the
# translation template regenerated first. Used by `composer zip` and the release workflow.
set -euo pipefail
cd "$(dirname "$0")/.."

slug=ruvelo-help-center
rm -rf build && mkdir -p "build/$slug"

cp -r ruvelo-help-center.php uninstall.php readme.txt LICENSE src blocks assets templates languages "build/$slug/"

if command -v wp >/dev/null 2>&1; then
    wp i18n make-pot "build/$slug" "build/$slug/languages/$slug.pot" --slug="$slug" --domain="$slug" \
        --package-name="Ruvelo Help Center" --headers='{"Report-Msgid-Bugs-To":"https://github.com/Ruvelo/wp-help-center/issues"}' --quiet
    cp "build/$slug/languages/$slug.pot" "languages/$slug.pot"
fi

# PHP's ZipArchive, so the build needs nothing beyond PHP.
php -r '
$zip = new ZipArchive();
$zip->open("build/".$argv[1].".zip", ZipArchive::CREATE | ZipArchive::OVERWRITE);
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("build/".$argv[1], FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $zip->addFile($file->getPathname(), substr($file->getPathname(), strlen("build/")));
}
$zip->close();
' "$slug"
echo "build/$slug.zip"
