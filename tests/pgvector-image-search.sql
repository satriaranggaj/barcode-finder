-- ONLY a disposable LOCAL PostgreSQL database with pgvector already installed.
-- psql -h 127.0.0.1 -d lensku_benchmark -v ON_ERROR_STOP=1 -f tests/pgvector-image-search.sql
-- Temporary tables shadow catalog names; all changes roll back. Never run on production.
BEGIN;
SET LOCAL maintenance_work_mem = '64MB';
SET LOCAL hnsw.ef_search = 100;
CREATE TEMP TABLE products (id bigint PRIMARY KEY, sku text);
CREATE TEMP TABLE product_photos (id bigint PRIMARY KEY, product_id bigint, path text);
CREATE TEMP TABLE product_photo_features (id bigint PRIMARY KEY, product_photo_id bigint, source_path text, version text, embedding vector(512));
INSERT INTO products SELECT i, 'fixture-'||i FROM generate_series(1,25000) i;
INSERT INTO product_photos SELECT i, 1+(i-1)/4, 'fixture-'||i FROM generate_series(1,100000) i;
SELECT setseed(0.42);
INSERT INTO product_photo_features
SELECT i, i, 'fixture-'||i, 'object-v1', ARRAY(SELECT random()::real FROM generate_series(1,512) d WHERE i>0)::vector
FROM generate_series(1,100000) i;
CREATE INDEX ON product_photo_features USING hnsw (embedding vector_cosine_ops) WHERE version='object-v1';
ANALYZE products;
ANALYZE product_photos;
ANALYZE product_photo_features;
SELECT embedding AS query FROM product_photo_features WHERE id=42 \gset
-- Actual application query shape, natural plan. Repeat with LIMIT 30/75/100.
EXPLAIN (ANALYZE, BUFFERS)
SELECT p.*, ph.path AS photo, nearest.id AS feature_id, ph.id AS photo_id, 1-nearest.distance AS similarity
FROM (SELECT id, product_photo_id, source_path, embedding <=> :'query'::vector AS distance
      FROM product_photo_features WHERE version='object-v1'
      ORDER BY embedding <=> :'query'::vector LIMIT 50) nearest
JOIN product_photos ph ON ph.id=nearest.product_photo_id AND ph.path=nearest.source_path
JOIN products p ON p.id=ph.product_id ORDER BY nearest.distance;
CREATE TEMP TABLE ann_results AS
SELECT id FROM product_photo_features WHERE version='object-v1' ORDER BY embedding <=> :'query'::vector LIMIT 100;
-- Exact comparator only: do not use forced plans as proof the ANN index is chosen.
SET LOCAL enable_indexscan=off;
SET LOCAL enable_bitmapscan=off;
CREATE TEMP TABLE exact_results AS
SELECT id FROM product_photo_features WHERE version='object-v1' ORDER BY embedding <=> :'query'::vector LIMIT 100;
SELECT count(*)/100.0 AS synthetic_ann_recall_at_100 FROM ann_results JOIN exact_results USING(id);
ROLLBACK;
