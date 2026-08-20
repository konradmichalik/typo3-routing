-- routing_benchmark is only installed in composer mode (see .ddev/.setup/project.sh's
-- FIXTURE_EXTENSION_DIRS); classic-mode installs never create this table, so guard the
-- insert instead of failing `ddev install --classic` for an extension it never activates.
SET @routing_benchmark_table_exists = (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'tx_routingbenchmark_domain_model_item'
);
SET @routing_benchmark_seed_sql = IF(
    @routing_benchmark_table_exists > 0,
    'INSERT INTO tx_routingbenchmark_domain_model_item (uid, pid, title) VALUES (1, 0, ''Benchmark Item'')',
    'DO 0'
);
PREPARE routing_benchmark_seed_stmt FROM @routing_benchmark_seed_sql;
EXECUTE routing_benchmark_seed_stmt;
DEALLOCATE PREPARE routing_benchmark_seed_stmt;
