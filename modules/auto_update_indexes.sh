#!/bin/bash
# Auto-update PROJECT_INDEX.json quando file PHP vengono modificati
#
# Installazione:
#   chmod +x modules/auto_update_indexes.sh
#   ln -s ../../modules/auto_update_indexes.sh .git/hooks/post-commit
#   ln -s ../../modules/auto_update_indexes.sh .git/hooks/post-merge
#
# Uso manuale:
#   ./modules/auto_update_indexes.sh

# Vai alla root del progetto
cd "$(dirname "$0")/.." || exit

# Trova tutti i file PHP modificati
if git rev-parse --is-inside-work-tree > /dev/null 2>&1; then
    # Se siamo in git, usa git diff
    CHANGED_FILES=$(git diff --name-only HEAD~1 HEAD 2>/dev/null | grep '\.php$' | grep '^modules/')
else
    # Altrimenti rigenera tutto
    CHANGED_FILES="all"
fi

if [ -z "$CHANGED_FILES" ] && [ "$CHANGED_FILES" != "all" ]; then
    echo "✅ Nessun file PHP modificato in /modules/"
    exit 0
fi

echo "🔄 Aggiornamento PROJECT_INDEX.json..."

# Estrai moduli unici dai file modificati
MODULES_TO_UPDATE=()

if [ "$CHANGED_FILES" = "all" ]; then
    echo "📦 Rigenerazione completa di tutti i moduli"
    php modules/generate_project_index.php all
else
    for file in $CHANGED_FILES; do
        # Estrai nome modulo (primo livello dopo modules/)
        module=$(echo "$file" | cut -d'/' -f2)
        
        # Aggiungi a array se non presente
        if [[ ! " ${MODULES_TO_UPDATE[@]} " =~ " ${module} " ]]; then
            MODULES_TO_UPDATE+=("$module")
        fi
    done

    # Aggiorna ogni modulo modificato
    for module in "${MODULES_TO_UPDATE[@]}"; do
        echo "📦 Aggiornamento modulo: $module"
        php modules/generate_project_index.php "$module"
    done
fi

echo "✅ PROJECT_INDEX.json aggiornati!"

# Se siamo in un commit hook, aggiungi i file modificati
if [ -n "$GIT_DIR" ]; then
    for module in "${MODULES_TO_UPDATE[@]}"; do
        if [ -f "modules/$module/PROJECT_INDEX.json" ]; then
            git add "modules/$module/PROJECT_INDEX.json"
        fi
    done
fi

