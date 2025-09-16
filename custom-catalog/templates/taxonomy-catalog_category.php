<?php
/* Template: Kategorie-Archiv für catalog_category (mit Header/Footer & Bearbeiten) */
defined('ABSPATH') || exit;
get_header();

$term    = get_queried_object();
$term_id = isset($term->term_id) ? (int)$term->term_id : 0;
?>
<main id="primary" class="site-main">
  <header class="cc-archive-header">
    <h1 class="cc-archive-title"><?php echo esc_html($term->name ?? 'Kategorie'); ?></h1>
    <?php if (!empty($term->description)): ?>
      <div class="cc-archive-desc"><?php echo wp_kses_post(wpautop($term->description)); ?></div>
    <?php endif; ?>
    <?php if (current_user_can('manage_categories') && $term_id): ?>
      <div class="cc-archive-actions" style="margin-top:8px;">
        <a class="button button-small cc-cat-edit-btn"
           href="<?php echo esc_url( add_query_arg('id', $term_id, home_url('/kategorie-bearbeiten/')) ); ?>">
          Bearbeiten
        </a>
      </div>
    <?php endif; ?>
  </header>

  <?php
  $q = new WP_Query([
    'post_type'      => 'catalog_item',
    'tax_query'      => [[
      'taxonomy' => 'catalog_category',
      'field'    => 'term_id',
      'terms'    => $term_id,
    ]],
    'orderby'        => 'title',
    'order'          => 'ASC',
    'posts_per_page' => 18,
    'paged'          => max(1, (int) get_query_var('paged')),
  ]);
  echo '<div class="cc-grid cols-3">';
  if ($q->have_posts()):
    while ($q->have_posts()): $q->the_post(); ?>
      <article class="cc-card">
        <a href="<?php the_permalink(); ?>">
          <?php if (has_post_thumbnail()) the_post_thumbnail('medium'); ?>
          <h3><?php the_title(); ?></h3>
        </a>
      </article>
    <?php endwhile;
  else:
    echo '<p>Keine Artikel in dieser Kategorie.</p>';
  endif;
  echo '</div>';

  the_posts_pagination();
  wp_reset_postdata();
  ?>
</main>
<?php get_footer(); ?>
