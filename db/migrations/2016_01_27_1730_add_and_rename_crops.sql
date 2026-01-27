UPDATE crops SET name = 'Cotton'        WHERE code = 'COTS';
UPDATE crops SET name = 'Sorghum'       WHERE code = 'GRSG';
UPDATE crops SET name = 'Wheat'         WHERE code = 'WWHT';
UPDATE crops SET name = 'Clover'        WHERE code = 'CLVR';
INSERT INTO crops (code, name) VALUES
   ('RNMZ', 'Rainfed Maize'),
   ('IRMZ', 'Irrigated Maize'),
   ('IRWT', 'Irrigated Wheat'),
   ('RNWT', 'Rainfed Wheat'),
   ('TEFK', 'Teff'),
   ('IRPO', 'Irrigated Potato'),
   ('ECUL', 'Eucalyptus'),
   ('GORD', 'Avocado')
ON CONFLICT (code) DO NOTHING;