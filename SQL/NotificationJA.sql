--
-- Fichier généré par SQLiteStudio v3.3.3 sur dim. juin 7 05:29:50 2026
--
-- Encodage texte utilisé : System
--
PRAGMA foreign_keys = off;
BEGIN TRANSACTION;

-- Table : Utilisateur
DROP TABLE IF EXISTS Utilisateur;

CREATE TABLE Utilisateur (
    Id_Utilisateur INTEGER      PRIMARY KEY
                                UNIQUE
                                NOT NULL,
    Login          TEXT,
    Password       TEXT,
    Nom            TEXT,
    Prenom         TEXT,
    Role           VARCHAR (30),
    Actif          BOOLEAN,
    Id_Departement INTEGER      REFERENCES Departement (Id_Departement),
    ChangeLogin    INTEGER      NOT NULL
                                DEFAULT 1
);

INSERT INTO Utilisateur (
                            Id_Utilisateur,
                            Login,
                            Password,
                            Nom,
                            Prenom,
                            Role,
                            Actif,
                            Id_Departement,
                            ChangeLogin
                        )
                        VALUES (
                            1,
                            'CHAUTARD',
                            'wRHJkdzfaI9kB+MjJUZpaIORZphAYpRQMqo3BhQNvdorvsp2u1zWUyGN6/bMMT7f',
                            'CHAUTARD',
                            'Patrick',
                            'Développeur',
                            1,
                            76,
                            0
                        );

INSERT INTO Utilisateur (
                            Id_Utilisateur,
                            Login,
                            Password,
                            Nom,
                            Prenom,
                            Role,
                            Actif,
                            Id_Departement,
                            ChangeLogin
                        )
                        VALUES (
                            2,
                            'YVON',
                            'ROytO02+sgiGcNgIaF5kW2ombyGyGslKEunB+Q++tRMoQI6UHOdvoXVq+Igp75K/',
                            'YVON',
                            'Nathan',
                            'Utilisateur',
                            1,
                            50,
                            1
                        );

INSERT INTO Utilisateur (
                            Id_Utilisateur,
                            Login,
                            Password,
                            Nom,
                            Prenom,
                            Role,
                            Actif,
                            Id_Departement,
                            ChangeLogin
                        )
                        VALUES (
                            3,
                            'AZE',
                            'IYqrQm0/Yn7uAVNK9XxgQYfAI67f9cwCBD/BzR9lunuzOA6atkQlfK/7qbHUBcZi',
                            'AZE',
                            'Didier',
                            'Utilisateur',
                            1,
                            61,
                            1
                        );

INSERT INTO Utilisateur (
                            Id_Utilisateur,
                            Login,
                            Password,
                            Nom,
                            Prenom,
                            Role,
                            Actif,
                            Id_Departement,
                            ChangeLogin
                        )
                        VALUES (
                            4,
                            'ESCOT',
                            'kdBcZYnJHvbiIFOB6SDoYY9gRK/gRLeNRkarvwCGXWcjHvmYTGIbNWUztWbIrVHM',
                            'ESCOT',
                            'Marc',
                            'Utilisateur',
                            1,
                            61,
                            1
                        );

INSERT INTO Utilisateur (
                            Id_Utilisateur,
                            Login,
                            Password,
                            Nom,
                            Prenom,
                            Role,
                            Actif,
                            Id_Departement,
                            ChangeLogin
                        )
                        VALUES (
                            5,
                            'LESEUR27',
                            '8yNWg1iOmsU3WkqnsbYqceXt2NfL3n8P3KBiyu71vBSB3hhAQx/CpumXSRBLGXHL',
                            'LESEUR',
                            'Gérard',
                            'Utilisateur',
                            1,
                            27,
                            1
                        );

INSERT INTO Utilisateur (
                            Id_Utilisateur,
                            Login,
                            Password,
                            Nom,
                            Prenom,
                            Role,
                            Actif,
                            Id_Departement,
                            ChangeLogin
                        )
                        VALUES (
                            6,
                            'LESEUR76',
                            'CTqKeEFviAv0i2jp99htSbDBy5aDvh8LWQFxorbS4avtwRHLiWUtuvPQm6Z10mBZ',
                            'LESEUR',
                            'Gérard',
                            'Utilisateur',
                            1,
                            76,
                            1
                        );


-- Index : sqlite_autoindex_Utilisateur_1
DROP INDEX IF EXISTS sqlite_autoindex_Utilisateur_1;

CREATE INDEX sqlite_autoindex_Utilisateur_1 ON Utilisateur (
    Id_Utilisateur COLLATE BINARY
);


COMMIT TRANSACTION;
PRAGMA foreign_keys = on;
