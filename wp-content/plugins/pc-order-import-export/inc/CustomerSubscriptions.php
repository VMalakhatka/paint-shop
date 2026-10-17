<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Marketing interests only. Waitlist consent and transactional messages remain independent. */
final class CustomerSubscriptions {
    public const META = '_pcoe_subscriptions_v1';
    public static function topics(): array {
        return ['price'=>__('Price lists','pc-order-import-export'), 'arrival'=>__('Stock arrivals','pc-order-import-export'),
            'news'=>__('New products and news','pc-order-import-export'), 'promotion'=>__('Promotions and special offers','pc-order-import-export')];
    }
    public static function profile(int $user): array {
        $saved=get_user_meta($user,self::META,true);
        return is_array($saved) ? $saved : ['version'=>1,'topics'=>array_keys(self::topics()),'scope'=>'all','rules'=>[]];
    }
    public static function fingerprint(int $user): string { return hash('sha256',wp_json_encode(self::profile($user))); }
    public static function brands(): array {
        // Do not expose supplier-only terms hidden from the public catalogue.
        return function_exists('psu_catalog_supplier_terms') ? psu_catalog_supplier_terms(true) : [];
    }
    public static function categories(): array {
        if(!class_exists('PSU_Category_Menu'))return [];
        $index=\PSU_Category_Menu::index();if(is_wp_error($index))return [];
        $ids=array_keys(array_diff_key($index['visible'],$index['blocked']));
        if(!$ids)return [];
        $terms=get_terms(['taxonomy'=>'product_cat','include'=>$ids,'hide_empty'=>false,'orderby'=>'name']);
        return is_wp_error($terms)?[]:$terms;
    }
    public static function normalize(array $input,array $brands,array $categories): array {
        $topics=array_values(array_intersect(array_keys(self::topics()),(array)($input['topics']??[])));
        $scope=($input['scope']??'all')==='selected'?'selected':'all';$rules=[];
        if($scope==='selected')foreach(array_slice((array)($input['rules']??[]),0,10) as $row){
            if(!is_array($row) || empty($row['enabled']))continue;
            $topic=$row['topic']??'*';$brand=(int)($row['brand']??0);$category=(int)($row['category']??0);
            if(!is_string($topic) || !in_array($topic,array_merge(['*'],array_keys(self::topics())),true)
                || ($brand && !in_array($brand,$brands,true)) || ($category && !in_array($category,$categories,true)))
                throw new \RuntimeException(__('An interest is no longer available. Review your selections.','pc-order-import-export'));
            $rules[]=compact('topic','brand','category');
        }
        if($scope==='selected' && $topics && !$rules)throw new \RuntimeException(__('Enable at least one interest rule, or choose all products.','pc-order-import-export'));
        return ['version'=>1,'topics'=>$topics,'scope'=>$scope,'rules'=>$rules];
    }
    public static function matches(array $profile,string $topic,array $brands,array $categories): bool {
        if(!in_array($topic,$profile['topics']??[],true))return false;
        if(($profile['scope']??'')==='all')return true;
        foreach($profile['rules']??[] as $rule){
            if(($rule['topic']??'')!=='*' && ($rule['topic']??'')!==$topic)continue;
            if(!empty($rule['brand']) && !in_array((int)$rule['brand'],$brands,true))continue;
            if(!empty($rule['category']) && !in_array((int)$rule['category'],$categories,true))continue;
            return true;
        }
        return false;
    }
    private static function category_branch(array $ids): array {
        foreach($ids as $id){$parents=get_ancestors($id,'product_cat','taxonomy');$ids=array_merge($ids,$parents);}
        return array_values(array_unique(array_map('intval',$ids)));
    }
    public static function campaign(array $input,string $kind): array {
        $topic=(string)($input['mail_topic']??'');
        if($topic==='')$topic=$kind==='text'?'news':$kind;
        if(!isset(self::topics()[$topic]))throw new \RuntimeException('Invalid mailing topic');
        $brand=absint($input['mail_brand']??0);$category=absint($input['mail_category']??0);
        if(($brand && !in_array($brand,array_map('intval',wp_list_pluck(self::brands(),'term_id')),true))
            || ($category && !in_array($category,array_map('intval',wp_list_pluck(self::categories(),'term_id')),true)))
            throw new \RuntimeException(__('An interest is no longer available. Review your selections.','pc-order-import-export'));
        return ['topic'=>$topic,'brands'=>$brand?[$brand]:[],'categories'=>$category?self::category_branch([$category]):[]];
    }
    public static function product_tags(int $id): array {
        static $cache=[];
        if(isset($cache[$id]))return $cache[$id];
        $p=wc_get_product($id);if(!$p)return ['brands'=>[],'categories'=>[]];
        $parent=$p->get_parent_id()?:$id;
        $brands=wp_get_object_terms($parent,'product_brand',['fields'=>'ids']);
        $categories=wp_get_object_terms($parent,'product_cat',['fields'=>'ids']);
        if(is_wp_error($brands)||is_wp_error($categories))throw new \RuntimeException(__('Product interests could not be checked. Try again.','pc-order-import-export'));
        return $cache[$id]=['brands'=>array_map('intval',$brands),'categories'=>self::category_branch($categories)];
    }
    public static function products(array $profile,string $topic,array $ids): array {
        if(!in_array($topic,$profile['topics']??[],true))return [];
        if(($profile['scope']??'')==='all')return $ids;
        static $sets=[];
        $key=hash('sha256',wp_json_encode([$topic,$profile['topics'],$profile['scope'],$profile['rules'],$ids]));
        if(!isset($sets[$key]))$sets[$key]=array_values(array_filter($ids,static function($id)use($profile,$topic){$t=self::product_tags((int)$id);return self::matches($profile,$topic,$t['brands'],$t['categories']);}));
        return $sets[$key];
    }
    public static function select(string $name,array $terms,int $value,string $all): void {
        echo '<select style="max-width:100%" name="'.esc_attr($name).'"><option value="0">'.esc_html($all).'</option>';
        foreach($terms as $term){
            $label=$term->name;
            if($term->taxonomy==='product_cat'){
                $path=[];foreach(array_reverse(get_ancestors($term->term_id,'product_cat','taxonomy')) as $parent){$t=get_term($parent,'product_cat');if($t && !is_wp_error($t))$path[]=$t->name;}
                $label=implode(' / ',array_merge($path,[$label]));
            }
            echo '<option value="'.esc_attr($term->term_id).'" '.selected($value,$term->term_id,false).'>'.esc_html($label).'</option>';
        }
        echo '</select>';
    }
    public static function fields(int $user): void {
        $p=self::profile($user);$brands=self::brands();$categories=self::categories();
        wp_nonce_field('pcoe_subscriptions','pcoe_subscriptions_nonce');
        echo '<h3>'.esc_html__('My subscriptions and notifications','pc-order-import-export').'</h3><p>'.esc_html__('Choose topics and products for email mailings. Save using the account Save changes button. Waiting-list notices are configured separately in Waiting list.','pc-order-import-export').'</p>';
        foreach(self::topics() as $key=>$label)echo '<label style="display:block"><input type="checkbox" name="subscriptions[topics][]" value="'.$key.'" '.checked(in_array($key,$p['topics'],true),true,false).'> '.esc_html($label).'</label>';
        echo '<p><label>'.esc_html__('Product interests','pc-order-import-export').' <select name="subscriptions[scope]">';
        foreach(['all'=>__('All products','pc-order-import-export'),'selected'=>__('Only the interests below','pc-order-import-export')] as $key=>$label)echo '<option value="'.$key.'" '.selected($p['scope'],$key,false).'>'.esc_html($label).'</option>';
        echo '</select></label></p><p>'.esc_html__('Within a rule, both brand and category must match. Rules are combined. Categories include subcategories. Enable a rule with All brands and All categories for unrestricted news or promotions.','pc-order-import-export').'</p>';
        for($i=0;$i<max(5,count($p['rules']));$i++){
            $r=$p['rules'][$i]??['topic'=>'*','brand'=>0,'category'=>0];$name='subscriptions[rules]['.$i.']';
            echo '<fieldset style="margin:12px 0;padding:12px;border:1px solid #ddd;min-width:0"><legend>'.esc_html(sprintf(__('Interest %s','pc-order-import-export'),$i+1)).'</legend>';
            echo '<label><input type="checkbox" name="'.$name.'[enabled]" value="1" '.checked(isset($p['rules'][$i]),true,false).'> '.esc_html__('Use this rule','pc-order-import-export').'</label> <label>'.esc_html__('Topic','pc-order-import-export').' <select name="'.$name.'[topic]">';
            foreach(array_merge(['*'=>__('All selected topics','pc-order-import-export')],self::topics()) as $key=>$label)echo '<option value="'.$key.'" '.selected($r['topic'],$key,false).'>'.esc_html($label).'</option>';
            echo '</select></label><p><label>'.esc_html__('Catalogue brand','pc-order-import-export').' ';self::select($name.'[brand]',$brands,(int)$r['brand'],__('All brands','pc-order-import-export'));echo '</label></p><label>'.esc_html__('Category','pc-order-import-export').' ';self::select($name.'[category]',$categories,(int)$r['category'],__('All categories','pc-order-import-export'));echo '</label></fieldset>';
        }
    }
    public static function save(int $user,array $input): void {
        if($user!==get_current_user_id())return;
        if(!isset($input['pcoe_subscriptions_nonce']) || !wp_verify_nonce($input['pcoe_subscriptions_nonce'],'pcoe_subscriptions'))return;
        try{
            $p=self::normalize((array)($input['subscriptions']??[]),array_map('intval',wp_list_pluck(self::brands(),'term_id')),array_map('intval',wp_list_pluck(self::categories(),'term_id')));
            $p['updated_at']=time();update_user_meta($user,self::META,$p);
        }catch(\RuntimeException $e){wc_add_notice($e->getMessage(),'error');}
    }
    public static function summary(int $user): void {
        ManagerWorkspace::customer($user);$p=self::profile($user);
        echo '<section class="pcoe-card"><h3>'.esc_html__('Customer subscriptions','pc-order-import-export').'</h3>';
        if(get_user_meta($user,Broadcasts::OPT_OUT,true))echo '<p>'.esc_html__('Excluded from mailings','pc-order-import-export').'</p>';
        echo '<p>'.esc_html(implode(', ',array_intersect_key(self::topics(),array_fill_keys($p['topics'],true)))).'</p>';
        if($p['scope']==='all')echo '<p>'.esc_html__('All products','pc-order-import-export').'</p>';
        else foreach($p['rules'] as $r){
            $brand=$r['brand']?get_term($r['brand'],'product_brand'):null;$category=$r['category']?get_term($r['category'],'product_cat'):null;
            echo '<p>'.esc_html(implode(' · ',[$r['topic']==='*'?__('All selected topics','pc-order-import-export'):self::topics()[$r['topic']],$brand&&!is_wp_error($brand)?$brand->name:($r['brand']?'#'.$r['brand']:__('All brands','pc-order-import-export')),$category&&!is_wp_error($category)?$category->name:($r['category']?'#'.$r['category']:__('All categories','pc-order-import-export'))])).'</p>';
        }
        echo '<p>'.esc_html__('The customer controls these preferences in account details. Waiting-list consent is separate.','pc-order-import-export').'</p></section>';
    }
}
