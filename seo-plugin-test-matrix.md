# SEO Plugin Test Matrix

Purpose: manual QA for the SEOpress and Slim SEO integrations in SlyTranslate.

## Common setup

1. Activate SlyTranslate, Polylang, one configured AI provider, and exactly one SEO plugin under test.
2. Configure at least English and German in Polylang.
3. Create one English source post with no German translation yet.
4. Use clearly recognizable English copy in all SEO fields so partial or missing translation is obvious.
5. Add at least one URL-like value and one image URL that must never be translated.
6. Run each plugin section once from the block editor and once through the ability or REST flow if available in your environment.

## Suggested seed data

- Post title: English launch post for spring campaign
- Excerpt: Short English summary for the spring launch campaign.
- SEO title text: English SEO title for spring campaign
- SEO description text: English SEO description for spring campaign
- Focus keyword text: spring campaign
- Social title text: English Facebook title for spring campaign
- Social description text: English Facebook description for spring campaign
- Canonical or custom URL value: https://example.com/spring-campaign
- Image URL value: https://example.com/uploads/spring-campaign-og.jpg

## SEOpress

| ID | Scenario | Action | Expected result |
| --- | --- | --- | --- |
| SP-01 | Detection and first translation | Activate only SEOpress, open the English source post, run translate-content or use the editor button for German. | German translation is created successfully and linked in Polylang. |
| SP-02 | SEO text fields translated | Inspect the German post meta after translation. | The following keys contain German text: _seopress_titles_title, _seopress_titles_desc, _seopress_analysis_target_kw, _seopress_social_fb_title, _seopress_social_fb_desc, _seopress_social_twitter_title, _seopress_social_twitter_desc. |
| SP-03 | Analysis fields cleared | Inspect the German post meta right after translation. | The following keys are empty or removed so SEOpress can rebuild them: _seopress_content_analysis_api, _seopress_content_analysis_api_in_progress, _seopress_analysis_data, _seopress_analysis_data_oxygen. |
| SP-04 | Overwrite off | Translate once, change the English SEO title and description, then translate again with overwrite disabled. | Existing German translation is kept unchanged and the flow reports existing translation or skip behavior. |
| SP-05 | Overwrite on | Repeat SP-04 with overwrite enabled. | Existing German translation is updated and the translated SEOpress text fields reflect the new English source values. |
| SP-06 | Bulk workflow | Use get-untranslated and translate-content-bulk for at least two English posts. | Each German translation is created, SEOpress text fields are translated for each item, and no item fails because of SEO meta handling. |

## Slim SEO

| ID | Scenario | Action | Expected result |
| --- | --- | --- | --- |
| SL-01 | Detection and first translation | Activate only Slim SEO, open the English source post, run translate-content or use the editor button for German. | German translation is created successfully and linked in Polylang. |
| SL-02 | Only title and description translated | Store title and description inside the slim_seo meta array, then translate. | slim_seo.title and slim_seo.description contain German text in the target post. |
| SL-03 | URL and media safety | Add canonical, facebook_image, twitter_image, or similar URL-like values inside slim_seo before translation. | Non-text URL-like or media-like values remain byte-identical in the German post and are not translated or rewritten. |
| SL-04 | Overwrite off | Translate once, change the English slim_seo title and description, then translate again with overwrite disabled. | Existing German translation is kept unchanged and the flow reports existing translation or skip behavior. |
| SL-05 | Overwrite on | Repeat SL-04 with overwrite enabled. | slim_seo.title and slim_seo.description update in German, while canonical and image fields still match the original source values exactly. |
| SL-06 | Mixed meta safety | Add extra non-text keys to slim_seo, for example robots or custom flags. | Non-string or non-title and non-description values remain unchanged after translation. |

## Cross-checks

| ID | Scenario | Action | Expected result |
| --- | --- | --- | --- |
| CX-01 | Editor and ability parity | Run one translation from the editor panel and one through translate-content for the same plugin. | Resulting SEO meta on the German post is equivalent for both entry points. |
| CX-02 | Source integrity | Compare source post SEO meta before and after translation. | Source English post meta is unchanged. |
| CX-03 | Status visibility | Refresh translation status in the editor after creating the German post. | German translation is shown as available and links to the translated post. |

## Useful inspection commands

```bash
wp post meta get <translated_post_id> _seopress_titles_title
wp post meta get <translated_post_id> _seopress_titles_desc
wp post meta get <translated_post_id> _seopress_analysis_data
wp post meta get <translated_post_id> slim_seo --format=json
```

## Pass criteria

- SEOpress text fields are translated and analysis fields are cleared.
- Slim SEO only translates title and description inside slim_seo.
- URL-like and image-like Slim SEO values are preserved exactly.
- Overwrite behavior is consistent with the overwrite flag.
- Editor and ability entry points produce the same persisted SEO result.