#!/bin/sh
# Which stacks the repository in the current directory is built on, as one line:
#
#   sh detect-stack.sh   ->   magento hyva
#
# The review reads the guide for each (stack-<name>.md, from the review server or from
# references/), so it knows where that stack's wiring, security boundary and release
# steps are. Known stacks: laravel, magento, hyva and drupal. An empty line means
# none of them: the review then works from the method and whatever the repository
# writes down.
#
# REVIEW_STACK decides when it is set, as a comma- or space-separated list:
#
#   auto            detect (the default)
#   magento,hyva    exactly these
#   auto,hyva       detect, and add hyva
#   none            no stack guide at all
#
# hyva implies magento, since a Hyvä theme is a Magento theme.
#
# The precheck no longer runs it: the review server sends the stack with the context. The
# review step runs it only when the context has none, from a review server older than that.
# Detection reads composer.json and the tree, not vendor/, so it works before
# anything is installed. Runs under busybox ash and dash, needs
# only grep, and never fails: what it cannot read, it does not detect.
set -u

STACKS="laravel magento hyva drupal"

asked="${REVIEW_STACK:-auto}"
asked=$(printf '%s' "$asked" | tr ',;A-Z' '  a-z')

# A package in composer.json, as a key: "vendor/name": "...". Anywhere in the file
# rather than only under require, which also catches require-dev and a root package
# that replaces it - both of which still mean the code is written for that stack.
has_package() {
  [ -f composer.json ] && grep -Eq "\"$1\"[[:space:]]*:" composer.json
}

package_type() {
  [ -f composer.json ] && grep -Eq "\"type\"[[:space:]]*:[[:space:]]*\"$1\"" composer.json
}

any_file() {
  for f in "$@"; do
    [ -e "$f" ] && return 0
  done
  return 1
}

detect_laravel() {
  [ -f artisan ] || has_package 'laravel/framework'
}

# A Magento project, or a module or theme repository of its own.
detect_magento() {
  has_package 'magento/(product-community-edition|product-enterprise-edition|magento2-base|framework)' \
    || package_type 'magento2-(module|theme|library|language|component)' \
    || [ -f bin/magento ] \
    || { [ -f registration.php ] && grep -q 'ComponentRegistrar' registration.php; } \
    || any_file app/code/*/*/registration.php
}

# A Hyvä theme is a child of Hyva/*; a project can also only pull the packages in.
detect_hyva() {
  has_package 'hyva-themes/[a-z0-9_.-]+' && return 0
  for theme in app/design/frontend/*/*/theme.xml; do
    [ -f "$theme" ] && grep -q '<parent>Hyva/' "$theme" && return 0
  done
  any_file app/design/frontend/*/*/web/tailwind/tailwind.config.js
}

detect_drupal() {
  has_package 'drupal/(core|core-recommended|core-dev|recommended-project)' \
    || package_type 'drupal-(module|theme|profile|recipe|custom-module|custom-theme)' \
    || any_file web/core/lib/Drupal.php docroot/core/lib/Drupal.php core/lib/Drupal.php \
    || any_file web/modules/custom docroot/modules/custom
}

found=""
add() {
  case " $found " in *" $1 "*) ;; *) found="$found $1" ;; esac
}

for word in $asked; do
  case "$word" in
    none) ;;
    auto)
      for stack in $STACKS; do
        "detect_$stack" 2> /dev/null && add "$stack"
      done
      ;;
    *)
      case " $STACKS " in
        *" $word "*) add "$word" ;;
        *) echo "REVIEW_STACK names '$word', which is not one of: $STACKS, auto, none. Ignored." >&2 ;;
      esac
      ;;
  esac
done

case " $found " in *" hyva "*) add magento ;; esac

# In a fixed order, so the same repository always gives the same line.
out=""
for stack in $STACKS; do
  case " $found " in *" $stack "*) out="$out $stack" ;; esac
done
echo "${out# }"
