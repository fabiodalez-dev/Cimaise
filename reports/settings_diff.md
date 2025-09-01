# Settings Diff Report

Generated: 2025-09-01T14:41:32Z

## Only in template.sqlite (missing in complete_mysql_schema.sql)
- seo.local_business_geo_lat

## Only in complete_mysql_schema.sql (missing in template.sqlite)
- Gallery Fullscreen
- Grid Ampia
- Grid Classica
- Grid Compatta
- Magazine Split
- Masonry Portfolio

## Differing values/types for common keys
- Format: key | sqlite_value (type) | mysql_value (type)
- cache.ttl | 24 (number) | number ()
- gallery.default_template_id | 3 (number) | number ()
- image.breakpoints | {"sm":768,"md":1200,"lg":1920,"xl":2560,"xxl":3840} (string) | string ()
- image.formats | {"avif":true,"webp":true,"jpg":true} (string) | string ()
- image.preview | {"width":480,"height":null} (string) | string ()
- image.quality | {"avif":50,"webp":75,"jpg":85} (string) | string ()
- pagination.limit | 12 (number) | number ()
- performance.compression | true (boolean) | boolean ()
- seo.analytics_gtag | string (string) |  ()
- seo.analytics_gtm | string (string) |  ()
- seo.author_name | string (string) |  ()
- seo.author_url | string (string) |  ()
- seo.breadcrumbs_enabled | true (boolean) | boolean ()
- seo.canonical_base_url | string (string) |  ()
- seo.image_acquire_license_page | string (string) |  ()
- seo.image_alt_auto | true (boolean) | boolean ()
- seo.image_copyright_notice | string (string) |  ()
- seo.image_license_url | string (string) |  ()
- seo.lazy_load_images | true (boolean) | boolean ()
- seo.local_business_address | string (string) |  ()
- seo.local_business_city | string (string) |  ()
- seo.local_business_country | string (string) |  ()
- seo.local_business_enabled | false (boolean) | boolean ()
- seo.local_business_geo_lng | string (string) |  ()
- seo.local_business_name | string (string) |  ()
- seo.local_business_opening_hours | string (string) |  ()
- seo.local_business_phone | string (string) |  ()
- seo.local_business_postal_code | string (string) |  ()
- seo.local_business_type | ProfessionalService (string) | string ()
- seo.og_locale | en_US (string) | string ()
- seo.og_site_name | Photography Portfolio (string) | string ()
- seo.og_type | website (string) | string ()
- seo.organization_name | string (string) |  ()
- seo.organization_url | string (string) |  ()
- seo.photographer_area_served | string (string) |  ()
- seo.photographer_job_title | Professional Photographer (string) | string ()
- seo.photographer_same_as | string (string) |  ()
- seo.photographer_services | Professional Photography Services (string) | string ()
- seo.preload_critical_images | true (boolean) | boolean ()
- seo.robots_default | index,follow (string) | string ()
- seo.schema_enabled | true (boolean) | boolean ()
- seo.site_description | Professional photography portfolio showcasing creative work and artistic vision (string) | string ()
- seo.site_keywords | photography, portfolio, professional photographer, creative photography (string) | string ()
- seo.site_title | Photography Portfolio (string) | string ()
- seo.sitemap_enabled | true (boolean) | boolean ()
- seo.structured_data_format | json-ld (string) | string ()
- seo.twitter_card | summary_large_image (string) | string ()
- seo.twitter_creator | string (string) |  ()
- seo.twitter_site | string (string) |  ()
- site.logo | null (null) | null ()
- social.enabled | ["bluesky","facebook","pinterest","telegram","threads","whatsapp","x"] (string) | string ()
- social.order | ["bluesky","facebook","pinterest","telegram","threads","whatsapp","x"] (string) | string ()
- test.setting | 123 (number) | number ()
