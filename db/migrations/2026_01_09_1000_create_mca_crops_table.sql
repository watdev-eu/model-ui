CREATE TABLE mca_variable_values_crop (
  variable_set_id BIGINT NOT NULL
      REFERENCES mca_variable_sets(id) ON DELETE CASCADE,
  variable_id BIGINT NOT NULL
      REFERENCES mca_variables(id) ON DELETE RESTRICT,
  crop_code VARCHAR(8) NOT NULL
      REFERENCES crops(code) ON DELETE CASCADE,
  value_num DOUBLE PRECISION,
  value_text TEXT,
  value_bool BOOLEAN,
  PRIMARY KEY (variable_set_id, variable_id, crop_code)
);

CREATE INDEX mca_variable_values_crop_lookup
    ON mca_variable_values_crop (variable_set_id, crop_code);

INSERT INTO mca_variables (key, name, unit, description, data_type)
VALUES (
           'crop_price_usd_per_t',
           'Crop price',
           'USD/t',
           'Crop farm-gate price used for water economic efficiency',
           'number'
       )
ON CONFLICT (key) DO NOTHING;