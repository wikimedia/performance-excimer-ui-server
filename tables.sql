CREATE TABLE excimer_report (
    report_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    request_info LONGBLOB NOT NULL,
    speedscope_deflated LONGBLOB NOT NULL,
    period_us INT NOT NULL,
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),

    PRIMARY KEY (report_id),
    KEY (request_id),
    KEY (created)
);
