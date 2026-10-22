<?php
// Подключение стилей дочерней темы
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_style('generatepress-child-style', get_stylesheet_uri(), [], (string) filemtime(get_stylesheet_directory() . '/style.css'));
});

// Store behaviour belongs to plugins: Paint Shop UX (breadcrumbs),
// PSU Products Per Page (24 by default), Paint Core (checkout creation date).

// ВЕРХНИЙ БАР С ЛОГОТИПОМ (inline SVG) НАД ШАПКОЙ ТЕМЫ
add_action( 'generate_before_header', 'lavka_topbar', 1 );
function lavka_topbar() {
    ?>
    <div class="lavka-topbar">
        <div class="lavka-topbar__inner">
            <div class="lavka-topbar__logo">
                <?php lavka_inline_svg_logo(); ?>
            </div>
    <div class="lavka-topbar__info">
        <div>м. Київ, ТЦ «Олімпійський», +38044&nbsp;593-26-05</div>
        <div>м. Одеса, Ланжеронівська, 17, +38063&nbsp;857-17-68</div>
        <div>Оптові продажі, +38050&nbsp;348-01-38</div>
    </div>
        </div>
    </div>
    <?php
}

function lavka_inline_svg_logo() {
    $svg_path = get_stylesheet_directory() . '/assets/logo.svg';

    // Для отладки: если файл не найден, покажем заглушку
    if ( ! file_exists( $svg_path ) ) {
        echo '<a href="' . esc_url( home_url('/') ) . '" class="site-logo site-logo--svg site-logo--missing" aria-label="' . esc_attr( get_bloginfo('name') ) . '">LOGO.SVG NOT FOUND</a>';
        if ( function_exists( 'error_log' ) ) {
            error_log( 'lavka_inline_svg_logo: logo.svg not found at ' . $svg_path );
        }
        return;
    }

    $svg = file_get_contents( $svg_path );

    if ( ! $svg ) {
        echo '<a href="' . esc_url( home_url('/') ) . '" class="site-logo site-logo--svg site-logo--missing" aria-label="' . esc_attr( get_bloginfo('name') ) . '">SVG READ ERROR</a>';
        if ( function_exists( 'error_log' ) ) {
            error_log( 'lavka_inline_svg_logo: unable to read logo.svg at ' . $svg_path );
        }
        return;
    }

    // Оборачиваем в ссылку на главную
    echo '<a href="' . esc_url( home_url('/') ) . '" class="site-logo site-logo--svg" aria-label="' . esc_attr( get_bloginfo('name') ) . '">';
    echo $svg;  // здесь прямо вставляется <svg>...</svg>
    echo '</a>';
}
// Заменяем стандартный логотип GeneratePress (букву "P") на наш SVG-логотип
add_filter( 'generate_logo_output', function( $output ) {
    ob_start();
    lavka_inline_svg_logo();
    return ob_get_clean();
});
