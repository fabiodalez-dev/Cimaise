# Schema del Database (photoCMS)

Questo documento riassume lo schema attuale del database MySQL per photoCMS. Charset: `utf8mb4`, Collation: `utf8mb4_0900_ai_ci`.

## Tabelle Core
- users: id, email (unico), password_hash, role, created_at.
- categories: id, name, slug (unico), sort_order, created_at. Indici: slug, sort_order.
- tags: id, name, slug (unico), created_at. Indici: slug.
- albums: id, title, slug (unico), category_id (FK), excerpt, body, cover_image_id, shoot_date, published_at, is_published, sort_order, timestamps. Indici: slug, category_id, is_published, published_at, sort_order.
- album_tag: PK composta (album_id, tag_id). FK verso albums e tags (CASCADE).

## Lookup Fotografici (in arrivo)
- cameras: make, model (UNIQUE make+model).
- lenses: brand, model, focal_min, focal_max, aperture_min (UNIQUE brand+model).
- films: brand, name, iso, format, type (UNIQUE brand+name+iso+format).
- developers: name, process, notes (UNIQUE name+process).
- labs: name, city, country (UNIQUE name+city).

## Immagini (in arrivo)
- images: legata a albums; info file (path/hash/mime/dimensioni), accessibilità (alt, caption), EXIF JSON, metadati strutturati (FK: camera/lens/film/developer/lab), campi custom, processo (digital/analog/hybrid), exposure (iso/shutter/aperture), ordinamento e timestamp. Indici: album_id, sort_order, file_hash, camera_id, lens_id, film_id, developer_id, lab_id, process, iso.
- image_variants: per immagine, variante (xs…xl), formato (avif/webp/jpg), path, dimensioni e peso. UNIQUE (image_id, variant, format). FK CASCADE.

## Migrazioni
- 0001_init_core.sql: tabelle core (users, categories, tags, albums, album_tag).
- 0002_lookups.sql: lookup fotografici.
- 0003_images.sql: images e image_variants.

Esegui con: `php bin/console db:migrate`.
