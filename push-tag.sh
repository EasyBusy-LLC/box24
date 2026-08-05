#!/usr/bin/env bash

set -Eeuo pipefail

REMOTE="${REMOTE:-origin}"
VERSION_FILE="${VERSION_FILE:-easybusychats/install/version.php}"

if [[ ! -f "$VERSION_FILE" ]] && [[ -f "app/local/modules/easybusychats/install/version.php" ]]; then
    VERSION_FILE="app/local/modules/easybusychats/install/version.php"
fi

if [[ ! -f "$VERSION_FILE" ]]; then
    echo "Не найден файл версии: $VERSION_FILE" >&2
    exit 1
fi

git rev-parse --is-inside-work-tree >/dev/null

BRANCH="$(git symbolic-ref --quiet --short HEAD || true)"
if [[ -z "$BRANCH" ]]; then
    echo "Нельзя создать релиз из detached HEAD." >&2
    exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
    echo "Есть незакоммиченные изменения. Сначала создай commit." >&2
    git status --short
    exit 1
fi

VERSION="$(
    sed -nE "s/^[[:space:]]*['\"]VERSION['\"][[:space:]]*=>[[:space:]]*['\"]([^'\"]+)['\"].*/\1/p" \
        "$VERSION_FILE" |
        head -n 1
)"

if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]]; then
    echo "Некорректная версия в $VERSION_FILE: $VERSION" >&2
    exit 1
fi

TAG="v${VERSION}"

if git rev-parse --verify --quiet "refs/tags/$TAG" >/dev/null; then
    echo "Локальный тег $TAG уже существует." >&2
    exit 1
fi

if git ls-remote --exit-code --tags "$REMOTE" "refs/tags/$TAG" >/dev/null 2>&1; then
    echo "Тег $TAG уже существует в $REMOTE." >&2
    exit 1
fi

git tag -a "$TAG" -m "EasyBusy Chats $TAG"

if ! git push --atomic "$REMOTE" "$BRANCH" "refs/tags/$TAG"; then
    echo "Push завершился ошибкой. Локальный тег $TAG оставлен." >&2
    exit 1
fi

echo "Готово:"
echo "  Ветка: $BRANCH"
echo "  Версия: $VERSION"
echo "  Тег:    $TAG"
echo "  Commit: $(git rev-parse --short HEAD)"