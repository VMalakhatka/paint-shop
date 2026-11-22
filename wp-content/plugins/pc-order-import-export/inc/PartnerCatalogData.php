<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Public product content plus the authenticated customer's prices. Never exports supplier metadata. */
final class PartnerCatalogData {
    const LIMIT = 500;
    /** Brand labels permitted by the storefront, keyed by WooCommerce term ID. */
    public static function brand_names(): array {
        $terms = function_exists('psu_catalog_supplier_terms') ? psu_catalog_supplier_terms(true) : [];
        $names = [];
        foreach ($terms as $term) {
            $names[(int)$term->term_id] = html_entity_decode($term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $names;
    }

    private static function brand_filter_ids(int $id): array {
        $public = self::brand_names();
        if (!isset($public[$id])) {
            throw new \RuntimeException(__('Unavailable selection — choose again','pc-order-import-export'));
        }
        $children = is_taxonomy_hierarchical('product_brand') ? get_term_children($id, 'product_brand') : [];
        if (is_wp_error($children)) {
            throw new \RuntimeException(__('Could not read categories.','pc-order-import-export'));
        }
        return array_values(array_intersect(array_merge([$id], array_map('intval', $children)), array_keys($public)));
    }

    public static function skus(string $text): array {
        if (strlen($text)>100000) throw new \RuntimeException(__('Use up to 500 SKUs, one per line.','pc-order-import-export'));
        $rows=array_values(array_unique(array_filter(array_map('trim',preg_split('/\R/u',$text)),static fn($v)=>$v!=='')));
        if(count($rows)>self::LIMIT) throw new \RuntimeException(__('Use up to 500 SKUs, one per line.','pc-order-import-export'));
        foreach($rows as $sku) if(strlen($sku)>190 || preg_match('/[\x00-\x1f]/',$sku)) throw new \RuntimeException(__('Invalid SKU list.','pc-order-import-export'));
        return $rows;
    }
    public static function selection(array $input): array {
        foreach(['skus','category','brand'] as $key) if(isset($input[$key]) && !is_scalar($input[$key])) throw new \RuntimeException(__('Invalid selection.','pc-order-import-export'));
        $s=['skus'=>self::skus((string)($input['skus']??'')), 'category'=>absint($input['category']??0),'brand'=>absint($input['brand']??0)];
        if ($s['brand']) self::brand_filter_ids($s['brand']);
        if ($s['category'] && !term_exists($s['category'],'product_cat')) throw new \RuntimeException(__('Invalid selection.','pc-order-import-export'));
        if(!$s['skus'] && !$s['category'] && !$s['brand']) throw new \RuntimeException(__('Select a category, brand or SKU list.','pc-order-import-export'));
        return $s;
    }
    public static function ids(array $s): array {
        $ids=[];$missing=[];
        if($s['skus']) {
            foreach($s['skus'] as $sku){$id=wc_get_product_id_by_sku($sku);if($id)$ids[]=$id;else $missing[]=$sku;}
        } else {
            $tax=[];
            if ($s['category']) $tax[] = ['taxonomy'=>'product_cat','terms'=>[$s['category']],'include_children'=>true];
            if ($s['brand']) $tax[] = ['taxonomy'=>'product_brand','terms'=>self::brand_filter_ids($s['brand']),'include_children'=>false];
            $q=new \WP_Query(['post_type'=>'product','post_status'=>'publish','has_password'=>false,'fields'=>'ids','posts_per_page'=>self::LIMIT+1,'no_found_rows'=>true,'orderby'=>'ID','order'=>'ASC','tax_query'=>$tax]);
            if(count($q->posts)>self::LIMIT)throw new \RuntimeException(__('More than 500 products. Narrow the selection; no partial file was created.','pc-order-import-export'));
            foreach($q->posts as $id){$p=wc_get_product($id);if(!$p)continue;if($p->is_type('variable'))$ids=array_merge($ids,$p->get_children());else $ids[]=$id;if(count($ids)>self::LIMIT)break;}
        }
        if(count($ids)>self::LIMIT)throw new \RuntimeException(__('More than 500 products. Narrow the selection; no partial file was created.','pc-order-import-export'));
        return [array_values(array_unique($ids)),$missing];
    }
    public static function products(array $ids,array $s): \Generator {
        $brandIds = $s['brand'] ? self::brand_filter_ids($s['brand']) : [];
        $categoryIds = [];
        if ($s['category']) {
            $children = get_term_children($s['category'], 'product_cat');
            if (is_wp_error($children)) throw new \RuntimeException(__('Could not read categories.','pc-order-import-export'));
            $categoryIds = array_merge([$s['category']], $children);
        }
        foreach(BroadcastPricing::products($ids) as $p){
            $parent=$p->get_parent_id()?:$p->get_id();
            if(post_password_required($parent) || !apply_filters('woocommerce_product_is_visible',true,$parent))continue;
            if ($categoryIds && !has_term($categoryIds, 'product_cat', $parent)) continue;
            if ($brandIds && !has_term($brandIds, 'product_brand', $parent)) continue;
            yield $p;
        }
    }
    public static function photos($p): array {
        $parent=$p->get_parent_id()?wc_get_product($p->get_parent_id()):null;
        $ids=array_merge([$p->get_image_id()?:($parent?$parent->get_image_id():0)],$p->get_gallery_image_ids(),$parent?$parent->get_gallery_image_ids():[]);
        $urls=[];
        foreach(array_unique(array_filter($ids)) as $id){$u=wp_get_attachment_image_url($id,'full');if($u && preg_match('~^https?://~i',$u))$urls[]=$u;}
        return array_values(array_unique($urls));
    }
    public static function text(string $value): string {return trim(wp_strip_all_tags(strip_shortcodes(html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8'))));}
    public static function build(int $user,array $selection): array {
        return BroadcastPricing::scope($user,static function()use($user,$selection){
            [$ids,$missing]=self::ids($selection);
            return self::build_ids($user,$ids,$selection,PriceList::location_ids(),$missing);
        });
    }
    /** Bounded shared projection; CRM supplies its own warehouse selection and skips Java packaging. */
    public static function build_ids(int $user,array $ids,array $selection,array $locations,array $missing=[],bool $packaging=true,bool $require_rows=true): array {
        return BroadcastPricing::scope($user,static function()use($ids,$selection,$locations,$missing,$packaging,$require_rows){
            $rows=[];$seen=[];$categories=[];
            $brandNames = self::brand_names();
            foreach(PriceList::catalogue_rows(self::products($ids,$selection),$locations) as $entry){
                if(!isset($entry['product_id']))continue;
                $p=wc_get_product($entry['product_id']);$parent=$p->get_parent_id()?wc_get_product($p->get_parent_id()):$p;$v=$entry['values'];
                $categoryId=(int)$entry['category_id'];
                foreach(array_merge([$categoryId],get_ancestors($categoryId,'product_cat','taxonomy')) as $id){$term=get_term($id,'product_cat');if(!$term || is_wp_error($term))throw new \RuntimeException(__('Could not read categories.','pc-order-import-export'));$categories[(int)$id]=['name'=>$term->name,'parent'=>(int)$term->parent];}
                $retail=$p->get_regular_price('edit');
                $description=$p->get_description()?:$parent->get_description();$short=$p->get_short_description()?:$parent->get_short_description();
                $assignedBrandNames = [];
                foreach ($entry['brand_ids'] as $brandId) {
                    if (isset($brandNames[$brandId])) {
                        $assignedBrandNames[] = $brandNames[$brandId];
                    }
                }
                $attributes = [];
                foreach ($parent->get_attributes() as $key => $attribute) {
                    if (!$attribute->get_visible() || preg_match('/supplier|postav|постач|постав/i', $key)) {
                        continue;
                    }
                    $label = wc_attribute_label($key);
                    // A legacy brand attribute could otherwise re-publish a hidden name as an XML param.
                    $identity = '/^(?:pa_)?(?:product_brand|brand|brend|manufacturer|бренд|виробник|производитель|vyrobnyk|proizvoditel|торгова марка|торговая марка)$/iu';
                    if (preg_match($identity, $key) || preg_match($identity, trim($label))) {
                        continue;
                    }
                    $value = $p->get_attribute($key) ?: $parent->get_attribute($key);
                    if ($value !== '') {
                        $attributes[$label] = self::text($value);
                    }
                }
                $rows[]=['id'=>(int)$p->get_id(),'sku'=>$v[0],'gtin'=>$v[1],'name'=>self::text($v[2]),'category'=>$v[4],'category_id'=>$categoryId,
                    'brand'=>implode(', ', $assignedBrandNames),'description'=>self::text($description),'short_description'=>self::text($short),'cost'=>$v[5],
                    'retail'=>$retail!=='' && is_numeric($retail)?wc_get_price_to_display($p,['price'=>(float)$retail]):null,
                    'stock'=>$v[6],'url'=>$v[8],'images'=>self::photos($p),'attributes'=>$attributes,
                    'unit'=>(string)($p->get_meta('_edin_izmer')?:$parent->get_meta('_edin_izmer'))];
                $seen[]=$v[0];
            }
            $missing=array_values(array_unique(array_merge($missing,array_diff($selection['skus'],$seen))));
            if(!$rows && $require_rows)throw new \RuntimeException(__('No exportable products. Check SKUs and filters.','pc-order-import-export'));
            $warnings=[];
            try{$packs=$packaging && $rows?CommercialOffer::packaging(array_column($rows,'sku')):[];}catch(\Throwable $e){$packs=[];$warnings[]=__('Pack quantities are unavailable. Contact the manager if you need them.','pc-order-import-export');}
            foreach($rows as &$row)$row['pack']=$packs[$row['sku']]??null;unset($row);
            return ['rows'=>$rows,'categories'=>$categories,'missing'=>$missing,'warnings'=>$warnings,'at'=>gmdate('c'),'currency'=>get_woocommerce_currency()];
        });
    }
}
