--actually, this is not necessary: VARCHAR(64) is the same as VARCHAR(128) in sqlite
PRAGMA foreign_keys=OFF;
BEGIN TRANSACTION;
CREATE TABLE /*_*/new_wss_namespaces (
      namespace_id INT NOT NULL PRIMARY KEY,
      namespace_key VARCHAR(32) NOT NULL UNIQUE,
      namespace_name VARCHAR(128) NOT NULL UNIQUE,
      description VARCHAR(1024) NOT NULL,
      creator_id INT NOT NULL,
      created_on BIGINT NOT NULL,
      archived BOOLEAN NOT NULL DEFAULT FALSE
) /*$wgDBTableOptions*/;
INSERT INTO /*_*/new_wss_namespaces(
  namespace_id,
  namespace_key,
  namespace_name,
  description,
  creator_id,
  created_on,
  archived
) SELECT namespace_id, namespace_key,namespace_name,description,creator_id,created_on,archived FROM /*_*/wss_namespaces;
DROP TABLE /*_*/wss_namespaces;
ALTER TABLE /*_*/new_wss_namespaces RENAME TO /*_*/wss_namespaces;
PRAGMA foreign_key_check;
COMMIT TRANSACTION;
