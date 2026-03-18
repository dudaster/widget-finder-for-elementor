#!/usr/bin/env python3
"""
Build compact widget JSON from the SQLite source database.

Output: data/widgets.json  (format v1)

Format v1
─────────
{
  "v":  1,
  "it": ["elementor_icon_class", "custom_icon_class", ...],   // icon-type lookup; index 0 = default
  "p":  {
    "plugin-slug": [
      "Plugin Name",   // 0  plugin_name
      50000,           // 1  active_installs
      48,              // 2  rating_average × 10, rounded integer (÷10 at import)
      58,              // 3  ranking_score, rounded integer
      [                // 4  widgets
        ["type", "Title", "icon-val", "widget title norm / alias list"],
        ["type", "Title", [1,"cls"], "norm"],   ← non-default icon uses [type_idx, value]
      ],
      ["map-key1", ...]  // 5  plugin_widgets (Elementor registration keys)
    ]
  }
}

Compression techniques applied
───────────────────────────────
  • Grouped by plugin → plugin_slug / plugin_name / installs / rating / score stored once
  • Array tuples (no repeated key strings per widget)
  • Icon-type lookup table; most-common type implicit (no value stored)
  • rating_average stored as integer ×10 (eliminates decimal point)
  • ranking_score stored as rounded integer
  • description_short omitted (always NULL in current dataset)
  • Raw search_text omitted; combined search_text reconstructed at import from
    plugin_slug + plugin_name + widget_type + widget_title + widget_title_norm
"""

import json
import re
import sqlite3
import sys
from collections import Counter, defaultdict
from pathlib import Path


def normalize(title: str) -> str:
    return re.sub(r"[^a-z0-9 ]+", " ", title.lower()).strip()


def build(sqlite_path: str, output_path: str) -> None:
    db = sqlite3.connect(sqlite_path)
    db.row_factory = sqlite3.Row

    widgets = db.execute(
        """
        SELECT plugin_slug, plugin_name, widget_type, widget_title,
               widget_title_norm, icon_type, icon_value,
               active_installs, rating_average, ranking_score
        FROM   widgets
        ORDER  BY plugin_slug, widget_type
        """
    ).fetchall()

    map_rows = db.execute(
        """
        SELECT plugin_slug, internal_widget_key
        FROM   plugin_widgets
        ORDER  BY plugin_slug
        """
    ).fetchall()

    db.close()

    # ── Icon-type lookup ────────────────────────────────────────────────────
    icon_counts = Counter(r["icon_type"] or "unknown" for r in widgets)
    icon_types = [t for t, _ in icon_counts.most_common()]   # most common = index 0 = default
    icon_idx = {t: i for i, t in enumerate(icon_types)}
    default_it = icon_types[0]

    # ── Plugin-widgets map ──────────────────────────────────────────────────
    plugin_map: dict[str, list[str]] = defaultdict(list)
    for row in map_rows:
        plugin_map[row["plugin_slug"]].append(row["internal_widget_key"])

    # ── Group widgets by plugin (deduplicate by plugin_slug + widget_type) ──
    plugin_meta: dict[str, dict] = {}
    plugin_widgets: dict[str, list] = defaultdict(list)
    seen: dict[str, set] = defaultdict(set)   # slug → {(widget_type, widget_title), ...}

    for row in widgets:
        slug   = row["plugin_slug"]
        wtype  = row["widget_type"] or ""
        wtitle = row["widget_title"] or ""

        if slug not in plugin_meta:
            plugin_meta[slug] = {
                "name":     row["plugin_name"] or "",
                "installs": int(row["active_installs"] or 0),
                "rating":   round(float(row["rating_average"] or 0) * 10),
                "score":    round(float(row["ranking_score"] or 0)),
            }

        # Skip rows that are exact duplicates of (widget_type, widget_title) for
        # this plugin — the source SQLite has ~100 such duplicate rows.
        key = (wtype, wtitle)
        if key in seen[slug]:
            continue
        seen[slug].add(key)

        itype  = row["icon_type"] or "unknown"
        ivalue = row["icon_value"] or ""
        norm   = (row["widget_title_norm"] or normalize(row["widget_title"] or "")).strip()

        # Encode icon: string when default type, [idx, value] otherwise
        icon = ivalue if itype == default_it else [icon_idx[itype], ivalue]

        # Widget tuple: [type, title, icon, norm]
        plugin_widgets[slug].append([
            wtype,
            wtitle,
            icon,
            norm,
        ])

    # ── Assemble output ─────────────────────────────────────────────────────
    # Start with widget-bearing plugins
    p_out: dict[str, list] = {}
    for slug, meta in plugin_meta.items():
        p_out[slug] = [
            meta["name"],
            meta["installs"],
            meta["rating"],
            meta["score"],
            plugin_widgets[slug],
            plugin_map.get(slug, []),
        ]

    # Add map-only plugins (have Elementor keys but no searchable widget rows)
    for slug, keys in plugin_map.items():
        if slug not in p_out:
            p_out[slug] = ["", 0, 0, 0, [], keys]

    output = {"v": 1, "it": icon_types, "p": p_out}

    Path(output_path).parent.mkdir(parents=True, exist_ok=True)
    with open(output_path, "w", encoding="utf-8") as f:
        json.dump(output, f, ensure_ascii=False, separators=(",", ":"))

    size = Path(output_path).stat().st_size
    total_widgets = sum(len(v[4]) for v in p_out.values())
    total_map     = sum(len(v[5]) for v in p_out.values())

    print(f"Output : {output_path}")
    print(f"Size   : {size:,} bytes  ({size / 1024 / 1024:.2f} MB)")
    print(f"Plugins: {len(p_out)}")
    print(f"Widgets: {total_widgets}")
    print(f"Map    : {total_map} keys")
    print(f"Icon types (idx→name): {list(enumerate(icon_types))}")


if __name__ == "__main__":
    src = sys.argv[1] if len(sys.argv) > 1 else "data/widgets.sqlite"
    dst = sys.argv[2] if len(sys.argv) > 2 else "data/widgets.json"
    build(src, dst)
