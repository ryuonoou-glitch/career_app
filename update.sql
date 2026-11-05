-- --------------------------------------------------------
-- DATABASE UPDATE SCRIPT
-- --------------------------------------------------------

-- 1. CLEANUP: Drop Unused Tables and Ghost Columns
ALTER TABLE `questions_skills` DROP COLUMN `skill_category_id`;
DROP TABLE IF EXISTS `skill_categories`;

ALTER TABLE `students` DROP COLUMN `specialization_id`;
DROP TABLE IF EXISTS `specializations`;

-- 2. INTEGRITY: Add Foreign Key Constraints for the Assessment Flow
-- Protect students table from accidental lookup deletions
ALTER TABLE `students` 
    ADD CONSTRAINT `fk_students_strand` FOREIGN KEY (`strand_id`) REFERENCES `strands`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_students_school_year` FOREIGN KEY (`school_year_id`) REFERENCES `school_years`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- Cascade delete assessments if a student is hard-deleted
ALTER TABLE `student_assessments` 
    ADD CONSTRAINT `fk_sa_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Cascade delete answers if an assessment is deleted
ALTER TABLE `student_answers` 
    ADD CONSTRAINT `fk_sa_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `student_assessments`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Cascade delete recommendations if an assessment is deleted
ALTER TABLE `recommendations` 
    ADD CONSTRAINT `fk_rec_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `student_assessments`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;
