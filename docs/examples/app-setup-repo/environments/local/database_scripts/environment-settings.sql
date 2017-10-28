-- Update Magento store URLs and cookie domains
REPLACE INTO `core_config_data` (`path`, `value`, `scope`, `scope_id`) VALUES

    -- Base URLs
    ('web/unsecure/base_url', 'http://local-admin.acmetunnels.com/', 'default', 0),
    ('web/secure/base_url', 'https://local-admin.acmetunnels.com/', 'default', 0),
    ('web/cookie/cookie_domain', 'local-admin.acmetunnels.com', 'default', 0),
    ('web/unsecure/base_url', 'http://local-acmetunnels.com/', 'websites', 1),
    ('web/secure/base_url', 'https://local-acmetunnels.com/', 'websites', 1),
    ('web/cookie/cookie_domain', 'local-acmetunnels.com', 'websites', 1)
;