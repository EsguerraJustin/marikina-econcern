START TRANSACTION;

INSERT INTO departments (name) VALUES
('Community Relations Office'),
('Engineering Office'),
('Human Resource Management Office'),
('Marikina City Tourism and Cultural Office'),
('Marikina Sports Center'),
('Office for Senior Citizen''s Affairs'),
('Office of Public Safety and Security'),
('Office of the Mayor'),
('Parks Development Office'),
('River Parks Authority'),
('School Repair and Maintenance Office'),
('Animal Rescue and Shelter'),
('Business Permit and License Office'),
('City Environmental Management Office'),
('City Health Office'),
('City Public Market Office'),
('City Social Welfare Development'),
('City Treasury Office')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO concern_types (department_id, name)
SELECT d.id, t.name
FROM departments d
JOIN (
  SELECT 'Community Relations Office' AS dept, 'Community / HOA Concerns' AS name

  UNION ALL SELECT 'Engineering Office', 'Permits'
  UNION ALL SELECT 'Engineering Office', 'Building and Construction'
  UNION ALL SELECT 'Engineering Office', 'Electrical Concerns'
  UNION ALL SELECT 'Engineering Office', 'Cable Bundling'
  UNION ALL SELECT 'Engineering Office', 'Traffic Light Repair'
  UNION ALL SELECT 'Engineering Office', 'Street Light Repair'
  UNION ALL SELECT 'Engineering Office', 'MWC Restoration'
  UNION ALL SELECT 'Engineering Office', 'Street Repair & Sidewalk Maintenance'
  UNION ALL SELECT 'Engineering Office', 'Declogging of Public Canals and Drains'

  UNION ALL SELECT 'Human Resource Management Office', 'Employee Concerns / Feedback'

  UNION ALL SELECT 'Marikina City Tourism and Cultural Office', 'Tours and Promotions'

  UNION ALL SELECT 'Marikina Sports Center', 'Schedule'
  UNION ALL SELECT 'Marikina Sports Center', 'Booking'
  UNION ALL SELECT 'Marikina Sports Center', 'Events'

  UNION ALL SELECT 'Office for Senior Citizen''s Affairs', 'Issuance of SC ID'
  UNION ALL SELECT 'Office for Senior Citizen''s Affairs', 'Birthday Subsidy'

  UNION ALL SELECT 'Office of Public Safety and Security', 'Illegal Parking/Road Obstructions'
  UNION ALL SELECT 'Office of Public Safety and Security', 'Illegal Vendors/Ordinance Violations'
  UNION ALL SELECT 'Office of Public Safety and Security', 'Public Order and Safety'
  UNION ALL SELECT 'Office of Public Safety and Security', 'Public Transportation'
  UNION ALL SELECT 'Office of Public Safety and Security', 'Complaint/Concerns'
  UNION ALL SELECT 'Office of Public Safety and Security', 'Violation Inquiries'

  UNION ALL SELECT 'Office of the Mayor', 'Other(s): Pls specify'

  UNION ALL SELECT 'Parks Development Office', 'Fabrication/Renovation/Repair/Repainting of Park Furniture'
  UNION ALL SELECT 'Parks Development Office', 'Parks and Playgrounds Development, Landscaping'
  UNION ALL SELECT 'Parks Development Office', 'Park Cleaning/Clearing, Grass Cutting, Soil Leveling'
  UNION ALL SELECT 'Parks Development Office', 'Tree Planting/Tree Maintenance'
  UNION ALL SELECT 'Parks Development Office', 'Tree Trimming'

  UNION ALL SELECT 'River Parks Authority', 'Riverparks Maintenance'
  UNION ALL SELECT 'River Parks Authority', 'Photoshoot/Educational Activities'
  UNION ALL SELECT 'River Parks Authority', 'Riverpark Restaurants'
  UNION ALL SELECT 'River Parks Authority', 'Security & Safety of Park Goers'

  UNION ALL SELECT 'School Repair and Maintenance Office', 'School Repairs and Requests'

  UNION ALL SELECT 'Animal Rescue and Shelter', 'Pet Registration & Vaccination'
  UNION ALL SELECT 'Animal Rescue and Shelter', 'Stray Animals'

  UNION ALL SELECT 'Business Permit and License Office', 'Business Inquiries'
  UNION ALL SELECT 'Business Permit and License Office', 'Permits'
  UNION ALL SELECT 'Business Permit and License Office', 'Inspection'

  UNION ALL SELECT 'City Environmental Management Office', 'Garbage Collection'
  UNION ALL SELECT 'City Environmental Management Office', 'Hakot Kuyagot'
  UNION ALL SELECT 'City Environmental Management Office', 'Tanker Sidewalk Cleaning and Scrubbing'
  UNION ALL SELECT 'City Environmental Management Office', 'Vacant Lot Grass Cutting and Soil Leveling'
  UNION ALL SELECT 'City Environmental Management Office', 'Eco Bricks'

  UNION ALL SELECT 'City Health Office', 'Permits and Certificates'
  UNION ALL SELECT 'City Health Office', 'CHO Schedule and Services'
  UNION ALL SELECT 'City Health Office', 'Health Center Schedule and Services'
  UNION ALL SELECT 'City Health Office', 'Medical Arts Schedule and Services'
  UNION ALL SELECT 'City Health Office', 'Animal Bite Treatment Center'

  UNION ALL SELECT 'City Public Market Office', 'Consumer Welfare Assistance/Complaints'

  UNION ALL SELECT 'City Social Welfare Development', 'PWD Registration'
  UNION ALL SELECT 'City Social Welfare Development', 'Solo Parents Registration'

  UNION ALL SELECT 'City Treasury Office', 'Real Property Tax'
  UNION ALL SELECT 'City Treasury Office', 'Business Tax'
  UNION ALL SELECT 'City Treasury Office', 'Other Payments'
) t ON t.dept = d.name
ON DUPLICATE KEY UPDATE name = VALUES(name);

DELETE ct
FROM concern_types ct
JOIN departments d ON d.id = ct.department_id
LEFT JOIN concerns c ON c.concern_type_id = ct.id
LEFT JOIN (
  SELECT 'Community Relations Office' AS dept, 'Community / HOA Concerns' AS name
  UNION ALL SELECT 'Engineering Office', 'Permits'
  UNION ALL SELECT 'Engineering Office', 'Building and Construction'
  UNION ALL SELECT 'Engineering Office', 'Electrical Concerns'
  UNION ALL SELECT 'Engineering Office', 'Cable Bundling'
  UNION ALL SELECT 'Engineering Office', 'Traffic Light Repair'
  UNION ALL SELECT 'Engineering Office', 'Street Light Repair'
  UNION ALL SELECT 'Engineering Office', 'MWC Restoration'
  UNION ALL SELECT 'Engineering Office', 'Street Repair & Sidewalk Maintenance'
  UNION ALL SELECT 'Engineering Office', 'Declogging of Public Canals and Drains'
  UNION ALL SELECT 'Human Resource Management Office', 'Employee Concerns / Feedback'
  UNION ALL SELECT 'Marikina City Tourism and Cultural Office', 'Tours and Promotions'
  UNION ALL SELECT 'Marikina Sports Center', 'Schedule'
  UNION ALL SELECT 'Marikina Sports Center', 'Booking'
  UNION ALL SELECT 'Marikina Sports Center', 'Events'
  UNION ALL SELECT 'Office for Senior Citizen''s Affairs', 'Issuance of SC ID'
  UNION ALL SELECT 'Office for Senior Citizen''s Affairs', 'Birthday Subsidy'
  UNION ALL SELECT 'Office of Public Safety and Security', 'Illegal Parking/Road Obstructions'
  UNION ALL SELECT 'Office of Public Safety and Security', 'Illegal Vendors/Ordinance Violations'
  UNION ALL SELECT 'Office of Public Safety and Security', 'Public Order and Safety'
  UNION ALL SELECT 'Office of Public Safety and Security', 'Public Transportation'
  UNION ALL SELECT 'Office of Public Safety and Security', 'Complaint/Concerns'
  UNION ALL SELECT 'Office of Public Safety and Security', 'Violation Inquiries'
  UNION ALL SELECT 'Office of the Mayor', 'Other(s): Pls specify'
  UNION ALL SELECT 'Parks Development Office', 'Fabrication/Renovation/Repair/Repainting of Park Furniture'
  UNION ALL SELECT 'Parks Development Office', 'Parks and Playgrounds Development, Landscaping'
  UNION ALL SELECT 'Parks Development Office', 'Park Cleaning/Clearing, Grass Cutting, Soil Leveling'
  UNION ALL SELECT 'Parks Development Office', 'Tree Planting/Tree Maintenance'
  UNION ALL SELECT 'Parks Development Office', 'Tree Trimming'
  UNION ALL SELECT 'River Parks Authority', 'Riverparks Maintenance'
  UNION ALL SELECT 'River Parks Authority', 'Photoshoot/Educational Activities'
  UNION ALL SELECT 'River Parks Authority', 'Riverpark Restaurants'
  UNION ALL SELECT 'River Parks Authority', 'Security & Safety of Park Goers'
  UNION ALL SELECT 'School Repair and Maintenance Office', 'School Repairs and Requests'
  UNION ALL SELECT 'Animal Rescue and Shelter', 'Pet Registration & Vaccination'
  UNION ALL SELECT 'Animal Rescue and Shelter', 'Stray Animals'
  UNION ALL SELECT 'Business Permit and License Office', 'Business Inquiries'
  UNION ALL SELECT 'Business Permit and License Office', 'Permits'
  UNION ALL SELECT 'Business Permit and License Office', 'Inspection'
  UNION ALL SELECT 'City Environmental Management Office', 'Garbage Collection'
  UNION ALL SELECT 'City Environmental Management Office', 'Hakot Kuyagot'
  UNION ALL SELECT 'City Environmental Management Office', 'Tanker Sidewalk Cleaning and Scrubbing'
  UNION ALL SELECT 'City Environmental Management Office', 'Vacant Lot Grass Cutting and Soil Leveling'
  UNION ALL SELECT 'City Environmental Management Office', 'Eco Bricks'
  UNION ALL SELECT 'City Health Office', 'Permits and Certificates'
  UNION ALL SELECT 'City Health Office', 'CHO Schedule and Services'
  UNION ALL SELECT 'City Health Office', 'Health Center Schedule and Services'
  UNION ALL SELECT 'City Health Office', 'Medical Arts Schedule and Services'
  UNION ALL SELECT 'City Health Office', 'Animal Bite Treatment Center'
  UNION ALL SELECT 'City Public Market Office', 'Consumer Welfare Assistance/Complaints'
  UNION ALL SELECT 'City Social Welfare Development', 'PWD Registration'
  UNION ALL SELECT 'City Social Welfare Development', 'Solo Parents Registration'
  UNION ALL SELECT 'City Treasury Office', 'Real Property Tax'
  UNION ALL SELECT 'City Treasury Office', 'Business Tax'
  UNION ALL SELECT 'City Treasury Office', 'Other Payments'
) official ON official.dept = d.name AND official.name = ct.name
WHERE c.id IS NULL
  AND official.dept IS NULL
  AND d.name IN (
    'Community Relations Office',
    'Engineering Office',
    'Human Resource Management Office',
    'Marikina City Tourism and Cultural Office',
    'Marikina Sports Center',
    'Office for Senior Citizen''s Affairs',
    'Office of Public Safety and Security',
    'Office of the Mayor',
    'Parks Development Office',
    'River Parks Authority',
    'School Repair and Maintenance Office',
    'Animal Rescue and Shelter',
    'Business Permit and License Office',
    'City Environmental Management Office',
    'City Health Office',
    'City Public Market Office',
    'City Social Welfare Development',
    'City Treasury Office'
  );

DELETE d
FROM departments d
LEFT JOIN concern_types ct ON ct.department_id = d.id
WHERE ct.id IS NULL
  AND d.name NOT IN (
    'Community Relations Office',
    'Engineering Office',
    'Human Resource Management Office',
    'Marikina City Tourism and Cultural Office',
    'Marikina Sports Center',
    'Office for Senior Citizen''s Affairs',
    'Office of Public Safety and Security',
    'Office of the Mayor',
    'Parks Development Office',
    'River Parks Authority',
    'School Repair and Maintenance Office',
    'Animal Rescue and Shelter',
    'Business Permit and License Office',
    'City Environmental Management Office',
    'City Health Office',
    'City Public Market Office',
    'City Social Welfare Development',
    'City Treasury Office'
  );

COMMIT;
