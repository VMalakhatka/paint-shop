<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Public product content plus the authenticated customer's prices. Never exports supplier metadata. */
final class PartnerCatalogData {
    const LIMIT = 500;
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
        foreach(['category'=>'product_cat','brand'=>'product_brand'] as $key=>$tax) if($s[$key] && !term_exists($s[$key],$tax)) throw new \RuntimeException(__('Invalid selection.','pc-order-import-export'));
        if(!$s['skus'] && !$s['category'] && !$s['brand']) throw new \RuntimeException(__('Select a category, brand or SKU list.','pc-order-import-export'));
        return $s;
    }
    public static function ids(array $s): array {
        $ids=[];$missing=[];
        if($s['skus']) {
            foreach($s['skus'] as $sku){$id=wc_get_product_id_by_sku($sku);if($id)$ids[]=$id;else $missing[]=$sku;}
        } else {
            $tax=[];
            foreach(['category'=>'product_cat','brand'=>'product_brand'] as $key=>$taxonomy) if($s[$key]) $tax[]=['taxonomy'=>$taxonomy,'terms'=>[$s[$key]],'include_children'=>true];
            $q=new \WP_Query(['post_type'=>'product','post_status'=>'publish','has_password'=>false,'fields'=>'ids','posts_per_page'=>self::LIMIT+1,'no_found_rows'=>true,'orderby'=>'ID','order'=>'ASC','tax_query'=>$tax]);
            if(count($q->posts)>self::LIMIT)throw new \RuntimeException(__('More than 500 products. Narrow the selection; no partial file was created.','pc-order-import-export'));
            foreach($q->posts as $id){$p=wc_get_product($id);if(!$p)continue;if($p->is_type('variable'))$ids=array_merge($ids,$p->get_children());else $ids[]=$id;if(count($ids)>self::LIMIT)break;}
        }
        if(count($ids)>self::LIMIT)throw new \RuntimeException(__('More than 500 products. Narrow the selection; no partial file was created.','pc-order-import-export'));
        return [array_values(array_unique($ids)),$missing];
    }
    public static function products(array $ids,array $s): \Generator {
        foreach(BroadcastPricing::products($ids) as $p){
            $parent=$p->get_parent_id()?:$p->get_id();
            if(post_password_required($parent) || !apply_filters('woocommerce_product_is_visible',true,$parent))continue;
            foreach(['category'=>'product_cat','brand'=>'product_brand'] as $key=>$tax) if($s[$key]){
                $terms=[$s[$key]];
                if(is_taxonomy_hierarchical($tax)){$children=get_term_children($s[$key],$tax);if(is_wp_error($children))throw new \RuntimeException(__('Could not read categories.','pc-order-import-export'));$terms=array_merge($terms,$children);}
                if(!has_term($terms,$tax,$parent))continue 2;
            }
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
        return BroadcastPricing::scope($user,static function()use($selection){
            [$ids,$missing]=self::ids($selection);$rows=[];$seen=[];$categories=[];$locations=PriceList::location_ids();
            foreach(PriceList::catalogue_rows(self::products($ids,$selection),$locations) as $entry){
                if(!isset($entry['product_id']))continue;
                $p=wc_get_product($entry['product_id']);$parent=$p->get_parent_id()?wc_get_product($p->get_parent_id()):$p;$v=$entry['values'];
                $categoryId=(int)$entry['category_id'];
                foreach(array_merge([$categoryId],get_ancestors($categoryId,'product_cat','taxonomy')) as $id){$term=get_term($id,'product_cat');if(!$term || is_wp_error($term))throw new \RuntimeException(__('Could not read categories.','pc-order-import-export'));$categories[(int)$id]=['name'=>$term->name,'parent'=>(int)$term->parent];}
                $retail=$p->get_regular_price('edit');
                $description=$p->get_description()?:$parent->get_description();$short=$p->get_short_description()?:$parent->get_short_description();
                $attributes=[];$brand='';
                foreach($parent->get_attributes() as $key=>$a){if(!$a->get_visible() || preg_match('/supplier|postav|постач|постав/i',$key))continue;$value=$p->get_attribute($key)?:$parent->get_attribute($key);if($value!=='')$attributes[wc_attribute_label($key)]=self::text($value);if(preg_match('/brand|brend|виробник|бренд|manufacturer|производитель/iu',wc_attribute_label($key)))$brand=self::text($value);}
                $rows[]=['id'=>(int)$p->get_id(),'sku'=>$v[0],'gtin'=>$v[1],'name'=>self::text($v[2]),'category'=>$v[4],'category_id'=>$categoryId,
                    'brand'=>$brand,'description'=>self::text($description),'short_description'=>self::text($short),'cost'=>$v[5],
                    'retail'=>$retail!=='' && is_numeric($retail)?wc_get_price_to_display($p,['price'=>(float)$retail]):null,
                    'stock'=>$v[6],'url'=>$v[8],'images'=>self::photos($p),'attributes'=>$attributes,
                    'unit'=>(string)($p->get_meta('_edin_izmer')?:$parent->get_meta('_edin_izmer'))];
                $seen[]=$v[0];
            }
            $missing=array_values(array_unique(array_merge($missing,array_diff($selection['skus'],$seen))));
            if(!$rows)throw new \RuntimeException(__('No exportable products. Check SKUs and filters.','pc-order-import-export'));
            $warnings=[];
            try{$packs=CommercialOffer::packaging(array_column($rows,'sku'));}catch(\Throwable $e){$packs=[];$warnings[]=__('Pack quantities are unavailable. Contact the manager if you need them.','pc-order-import-export');}
            foreach($rows as &$row)$row['pack']=$packs[$row['sku']]??null;unset($row);
            return ['rows'=>$rows,'categories'=>$categories,'missing'=>$missing,'warnings'=>$warnings,'at'=>gmdate('c'),'currency'=>get_woocommerce_currency()];
        });
    }
}
