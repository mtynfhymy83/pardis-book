INSERT INTO warehouses(public_id,name) VALUES('wh_main','انبار مرکزی') ON CONFLICT DO NOTHING;
INSERT INTO support_categories(id,title) VALUES('shipping_delay','تأخیر در ارسال'),('payment','پرداخت'),('catalog','کالا') ON CONFLICT DO NOTHING;
INSERT INTO settings(key,value) VALUES('commerce','{"currency":"TOMAN","taxRateBps":0,"reservationTtlSeconds":900}'),('shipping','{"basePrice":80000,"perKg":20000}') ON CONFLICT DO NOTHING;
INSERT INTO publishers(public_id,slug,name) VALUES('pub_oxford','oxford','Oxford University Press') ON CONFLICT DO NOTHING;
INSERT INTO series(public_id,slug,name,featured) VALUES('ser_ff','family-and-friends','Family and Friends',true) ON CONFLICT DO NOTHING;
INSERT INTO categories(public_id,slug,name) VALUES('cat_children','children-english','انگلیسی کودکان') ON CONFLICT DO NOTHING;
INSERT INTO products(public_id,slug,title,subtitle,publisher_id,series_id,category_id,status,search_text)
SELECT 'prd_ff3','family-and-friends-3-second-edition','Family and Friends 3','Second Edition — Student Book',p.id,s.id,c.id,'published','family and friends 3 second edition student book'
FROM publishers p,series s,categories c WHERE p.public_id='pub_oxford' AND s.public_id='ser_ff' AND c.public_id='cat_children' ON CONFLICT DO NOTHING;
INSERT INTO skus(public_id,product_id,code,isbn,attributes,weight_grams,minimum_order_quantity,reference_unit_price,fast_dispatch,status)
SELECT 'sku_ff3_sb_2e',id,'FF3-SB-2E','9780194808653','{"level":"3","edition":"2nd Edition","bookType":"student_book"}',400,5,285000,true,'in_stock' FROM products WHERE public_id='prd_ff3' ON CONFLICT DO NOTHING;
INSERT INTO pricing_tiers(sku_id,min_quantity,max_quantity,unit_price) SELECT id,5,9,285000 FROM skus WHERE public_id='sku_ff3_sb_2e' AND NOT EXISTS(SELECT 1 FROM pricing_tiers WHERE sku_id=skus.id);
INSERT INTO pricing_tiers(sku_id,min_quantity,max_quantity,unit_price) SELECT id,10,24,245000 FROM skus WHERE public_id='sku_ff3_sb_2e' AND (SELECT count(*) FROM pricing_tiers WHERE sku_id=skus.id)=1;
INSERT INTO pricing_tiers(sku_id,min_quantity,max_quantity,unit_price) SELECT id,25,49,230000 FROM skus WHERE public_id='sku_ff3_sb_2e' AND (SELECT count(*) FROM pricing_tiers WHERE sku_id=skus.id)=2;
INSERT INTO pricing_tiers(sku_id,min_quantity,max_quantity,unit_price) SELECT id,50,NULL,220000 FROM skus WHERE public_id='sku_ff3_sb_2e' AND (SELECT count(*) FROM pricing_tiers WHERE sku_id=skus.id)=3;
INSERT INTO inventory_balances(sku_id,warehouse_id,on_hand,reserved,safety_stock) SELECT s.id,w.id,120,0,0 FROM skus s,warehouses w WHERE s.public_id='sku_ff3_sb_2e' AND w.public_id='wh_main' ON CONFLICT DO NOTHING;
