-- General practice: data a new primary-care practice starts with.
-- Runs once, in the new practice's own database, right after installation.

-- Lists used by the Wellness Visit form. Registering them in the "lists" list makes them editable
-- under Administration > Lists.
INSERT INTO list_options (list_id, option_id, title, seq, is_default) VALUES
  ('lists', 'gp_visit_reason', 'Wellness Visit Reason', 900, 0),
  ('lists', 'gp_tobacco_use',  'Tobacco Use',           901, 0);

INSERT INTO list_options (list_id, option_id, title, seq, is_default) VALUES
  ('gp_visit_reason', 'annual',     'Annual wellness',          10, 1),
  ('gp_visit_reason', 'preventive', 'Preventive care',          20, 0),
  ('gp_visit_reason', 'chronic',    'Chronic condition review', 30, 0),
  ('gp_visit_reason', 'medreview',  'Medication review',        40, 0),
  ('gp_visit_reason', 'acute',      'Acute problem',            50, 0),
  ('gp_tobacco_use',  'never',      'Never',                    10, 1),
  ('gp_tobacco_use',  'former',     'Former',                   20, 0),
  ('gp_tobacco_use',  'current',    'Current',                  30, 0);

-- Encounter form "Wellness Visit". Forms built with the Layout Editor are listed from
-- layout_group_properties (grp_group_id = '' is the form itself, other rows are its sections).
INSERT INTO layout_group_properties (grp_form_id, grp_group_id, grp_title, grp_mapping, grp_activity) VALUES
  ('LBFgpwell', '',  'Wellness Visit', 'Clinical', 1),
  ('LBFgpwell', '1', 'Visit',          '',         1),
  ('LBFgpwell', '2', 'Screening',      '',         1),
  ('LBFgpwell', '3', 'Plan',           '',         1);

-- data_type: 1 list, 2 text, 3 text area, 4 date, 21 check box. uor: 1 optional, 2 required.
INSERT INTO layout_options
  (form_id, field_id, group_id, title, seq, data_type, uor, fld_length, max_length, list_id,
   titlecols, datacols, default_value, edit_options, description, fld_rows, list_backup_id, source,
   conditions, validation, codes)
VALUES
  ('LBFgpwell', 'visit_reason',   '1', 'Reason for visit',              10, 1,  2, 0, 0,   'gp_visit_reason', 1, 3, '', '', 'Main reason for this visit',    0, '', 'F', '', '', ''),
  ('LBFgpwell', 'last_wellness',  '1', 'Last wellness visit',           20, 4,  1, 0, 10,  '',                1, 3, '', '', 'Date of the previous visit',   0, '', 'F', '', '', ''),
  ('LBFgpwell', 'phq2_score',     '2', 'Depression screen (PHQ-2, 0-6)', 10, 2, 1, 3, 3,   '',                1, 3, '', '', 'Total PHQ-2 score',            0, '', 'F', '', '', ''),
  ('LBFgpwell', 'tobacco_use',    '2', 'Tobacco use',                   20, 1,  1, 0, 0,   'gp_tobacco_use',  1, 3, '', '', 'Current tobacco status',       0, '', 'F', '', '', ''),
  ('LBFgpwell', 'fall_past_year', '2', 'Fall in the past year',         30, 21, 1, 0, 0,   '',                1, 3, '', '', 'Patient reports a fall',       0, '', 'F', '', '', ''),
  ('LBFgpwell', 'plan_notes',     '3', 'Assessment and plan',           10, 3,  1, 40, 0,  '',                1, 3, '', '', 'Assessment and plan',          4, '', 'F', '', '', ''),
  ('LBFgpwell', 'follow_up_due',  '3', 'Follow-up due',                 20, 4,  1, 0, 10,  '',                1, 3, '', '', 'When the patient is due back', 0, '', 'F', '', '', '');
