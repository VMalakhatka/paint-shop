<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Scope catalogue reads to a customer without touching authentication or their cart. */
final class BroadcastPricing {
    public static function scope(int $user, callable $fn) {
        $previous=$GLOBALS['current_user']??null; $customer=WC()->customer; $session=WC()->session;
        $allow=static fn()=>true; $switched=false;
        try {
            $GLOBALS['current_user']=get_userdata($user);
            if(!$GLOBALS['current_user'])throw new \RuntimeException('Customer unavailable');
            WC()->customer=new \WC_Customer($user); WC()->session=null;
            $switched=switch_to_locale(get_user_locale($user));
            add_filter('rp_customer_price_context',$allow);
            return $fn();
        } finally {
            remove_filter('rp_customer_price_context',$allow);
            if($switched)restore_previous_locale();
            $GLOBALS['current_user']=$previous; WC()->customer=$customer; WC()->session=$session;
        }
    }

    public static function group(int $user): string {
        return self::scope($user,static function()use($user){
            $context=[wp_get_current_user()->roles,get_user_locale($user),get_woocommerce_currency(),
                WC()->customer->get_is_vat_exempt(),wc_tax_enabled()?WC()->customer->get_taxable_address():[],
                get_option('woocommerce_tax_display_shop'),get_option('woocommerce_prices_include_tax')];
            // The reviewed providers depend on roles, not the user ID. Unknown extensions
            // get their own file rather than risking a different customer's prices.
            if(!self::shared_providers())$context[]=$user;
            return hash('sha256',wp_json_encode($context));
        });
    }

    private static function shared_providers(): bool {
        global $wp_filter;
        $allowed=['Automattic\\WooCommerce\\Blocks\\Shipping\\ShippingController::filter_taxable_address','vp_role_price_override','WC_Deprecated_Filter_Hooks::maybe_handle_deprecated_hook',
            'Wpcpu_Frontend::get_price','Wpcpu_Frontend::get_regular_price','Wpcpu_Frontend::get_sale_price'];
        $hooks=['woocommerce_get_price','woocommerce_get_regular_price','woocommerce_get_sale_price','woocommerce_get_tax_class','woocommerce_get_tax_status','wpcpu_get_price','wpcpu_get_regular_price','wpcpu_get_sale_price',
            'wpcpu_get_price_role','wpcpu_ignore','wpcpu_use_wc_decimal_separator','rp_role_price_meta_prefix',
            'woocommerce_get_price_including_tax','woocommerce_get_price_excluding_tax','woocommerce_customer_taxable_address',
            'woocommerce_customer_get_is_vat_exempt','woocommerce_currency','woocommerce_adjust_non_base_location_prices','woocommerce_matched_rates','woocommerce_rate_percent','woocommerce_base_tax_rates','woocommerce_get_tax_location','woocommerce_matched_tax_rates',
            'woocommerce_find_rates','woocommerce_tax_round','woocommerce_calc_tax','woocommerce_price_inc_tax_amount','woocommerce_price_ex_tax_amount','woocommerce_product_is_taxable'];
        foreach(['price','regular_price','sale_price','tax_status','tax_class'] as $property)
            foreach(['woocommerce_product_get_','woocommerce_product_variation_get_'] as $prefix)$hooks[]=$prefix.$property;
        foreach($hooks as $hook)foreach(($wp_filter[$hook]->callbacks??[]) as $callbacks)foreach($callbacks as $callback){
            $fn=$callback['function'];
            $name=is_string($fn)?$fn:(is_array($fn)?(is_object($fn[0])?get_class($fn[0]):$fn[0]).'::'.$fn[1]:'unknown');
            if(!in_array($name,$allowed,true))return false;
        }
        return true;
    }

    public static function products(array $ids): \Generator {
        foreach(array_chunk($ids,250) as $batch){
            _prime_post_caches($batch,true,true);
            foreach($batch as $id){$p=wc_get_product($id);if($p && PriceList::eligible($p))yield $p;}
            if(wp_cache_supports('flush_runtime'))wp_cache_flush_runtime();
        }
    }

    /** Persist XLSX bytes privately in WordPress; no public media/upload URL. */
    public static function file(int $user,array $ids,array $options=[]): array {
        $options=CommercialOffer::options($options);
        return self::scope($user,static function()use($ids,$options){
            if(!ApprovalWorkbook::available())throw new \RuntimeException(__('Excel export is unavailable. Contact the site administrator.','pc-order-import-export'));
            $locations=PriceList::location_ids();
            if(!$locations)throw new \RuntimeException(__('Selling warehouses are not mapped.','pc-order-import-export'));
            wp_raise_memory_limit('admin');wc_set_time_limit(180);
            $rows=iterator_to_array(PriceList::catalogue_rows(self::products($ids),$locations),false);
            $count=count(array_filter($rows,static fn($row)=>isset($row['values'])));
            if(!$count)throw new \RuntimeException(__('No published products are available for this mailing.','pc-order-import-export'));
            $offer=$options['format']==='offer'?CommercialOffer::workbook($rows,$options['quantity']):null;
            $book=$offer?$offer['book']:PriceList::from_rows(PriceList::headers(),$rows);
            $path=BroadcastStore::temporary_file();
            try {
                $writer=new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book);$writer->setPreCalculateFormulas(false);$writer->save($path);
                $bytes=file_get_contents($path);
                if(!$bytes || strlen($bytes)>8*1024*1024)throw new \RuntimeException(__('The attachment exceeds the 8 MB mailing limit.','pc-order-import-export'));
                return ['content'=>base64_encode($bytes),'sha256'=>hash('sha256',$bytes),'count'=>$count,'photos'=>$offer['photos']??0,'at'=>gmdate('c')];
            } finally {if(is_file($path))unlink($path);$book->disconnectWorksheets();}
        });
    }
}
