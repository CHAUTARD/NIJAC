--
-- Fichier généré par SQLiteStudio v3.3.3 sur dim. juin 7 06:55:48 2026
--
-- Encodage texte utilisé : System
--
PRAGMA foreign_keys = off;
BEGIN TRANSACTION;

-- Table : Division
DROP TABLE IF EXISTS Division;

CREATE TABLE Division (
    Id_Division INTEGER PRIMARY KEY
                        UNIQUE
                        NOT NULL,
    Division    TEXT,
    Nom         TEXT    UNIQUE,
    Ord         INTEGER UNIQUE,
    NomLong     TEXT    NOT NULL
                        DEFAULT ''
);

INSERT INTO Division (
                         Id_Division,
                         Division,
                         Nom,
                         Ord,
                         NomLong
                     )
                     VALUES (
                         1,
                         'R3',
                         'Régional 3',
                         10,
                         'Régionale 3'
                     );

INSERT INTO Division (
                         Id_Division,
                         Division,
                         Nom,
                         Ord,
                         NomLong
                     )
                     VALUES (
                         2,
                         'R2',
                         'Régional 2',
                         20,
                         'Régionale 2'
                     );

INSERT INTO Division (
                         Id_Division,
                         Division,
                         Nom,
                         Ord,
                         NomLong
                     )
                     VALUES (
                         3,
                         'R1',
                         'Régional 1',
                         30,
                         'Régionale 1'
                     );

INSERT INTO Division (
                         Id_Division,
                         Division,
                         Nom,
                         Ord,
                         NomLong
                     )
                     VALUES (
                         4,
                         'PN',
                         'Prés National',
                         40,
                         'Pré-Nationale'
                     );

INSERT INTO Division (
                         Id_Division,
                         Division,
                         Nom,
                         Ord,
                         NomLong
                     )
                     VALUES (
                         5,
                         'N3',
                         'National 3',
                         50,
                         'Nationale 3'
                     );

INSERT INTO Division (
                         Id_Division,
                         Division,
                         Nom,
                         Ord,
                         NomLong
                     )
                     VALUES (
                         6,
                         'N2',
                         'National 2',
                         60,
                         'Nationale 2'
                     );

INSERT INTO Division (
                         Id_Division,
                         Division,
                         Nom,
                         Ord,
                         NomLong
                     )
                     VALUES (
                         7,
                         'N1',
                         'National 1',
                         70,
                         'Nationale 1'
                     );

INSERT INTO Division (
                         Id_Division,
                         Division,
                         Nom,
                         Ord,
                         NomLong
                     )
                     VALUES (
                         8,
                         'R1F',
                         'Régional Dame',
                         200,
                         'Régionale 1 F'
                     );

INSERT INTO Division (
                         Id_Division,
                         Division,
                         Nom,
                         Ord,
                         NomLong
                     )
                     VALUES (
                         9,
                         'PNF',
                         'Prés-national Dame',
                         210,
                         'Pré-Nationale F'
                     );

INSERT INTO Division (
                         Id_Division,
                         Division,
                         Nom,
                         Ord,
                         NomLong
                     )
                     VALUES (
                         10,
                         'R4',
                         'Régional 4',
                         5,
                         'Régionale 4'
                     );


-- Index : sqlite_autoindex_Division_1
DROP INDEX IF EXISTS sqlite_autoindex_Division_1;

CREATE INDEX sqlite_autoindex_Division_1 ON Division (
    Id_Division COLLATE BINARY
);


-- Index : sqlite_autoindex_Division_2
DROP INDEX IF EXISTS sqlite_autoindex_Division_2;

CREATE INDEX sqlite_autoindex_Division_2 ON Division (
    Nom COLLATE BINARY
);


-- Index : sqlite_autoindex_Division_3
DROP INDEX IF EXISTS sqlite_autoindex_Division_3;

CREATE INDEX sqlite_autoindex_Division_3 ON Division (
    Ord COLLATE BINARY
);


COMMIT TRANSACTION;
PRAGMA foreign_keys = on;
