<?php
/**
 * Shared template for rendering the Score Breakdown Modal payload.
 * Expected variables in scope:
 * - $course (array with course details, breakdown, etc.)
 * - $cRank (int, rank number)
 * - $studentId (int, used for counselor link if applicable)
 * - $isCounselorView (bool, whether to show counselor-specific actions)
 */

$cId      = (int)($course['id'] ?? $course['course_id'] ?? 0);
$cJobs    = $course['jobs']    ?? [];
$cInDistrict = $course['in_district_schools'] ?? [];
$cAltSchools = $course['alternative_schools'] ?? [];
$cHasRegion  = !empty($course['has_region']);
$cSelRegion  = $course['selected_region'] ?? '';
$cExpl    = trim($course['explanation'] ?? '');
$cPct     = (float)($course['match_percentage'] ?? 0);
$cDesc    = $course['description'] ?? '';

$cBreakdown = $course['breakdown'] ?? null;
$cClusterPct  = $cBreakdown ? round((float)$cBreakdown['cluster_alignment'], 1) : 0;
$cSkillsPct   = $cBreakdown ? round((float)$cBreakdown['skills_part'],     1) : 0;
$cCareerPct   = $cBreakdown ? round((float)$cBreakdown['career_part'],    1) : 0;
$cPersonPct   = $cBreakdown ? round((float)$cBreakdown['personality_part'],1) : 0;
$cStrandPct   = $cBreakdown ? round((float)$cBreakdown['strand_part'],     1) : 0;
?>
<div class="rc-modal-payload" data-course-id="<?php echo $cId; ?>" <?php echo isset($isAjax) && $isAjax ? '' : 'style="display:none;" aria-hidden="true"'; ?>>
    <!-- Header info -->
    <div class="rcm-header">
        <div style="flex:1;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                <span class="rc-rank-badge"><i class="fa-solid fa-check"></i> Rank #<?php echo $cRank; ?></span>
            </div>
            <h3 style="font-size:1.3rem;font-weight:700;color:#f8fafc;margin:0 0 4px;"><?php echo htmlspecialchars($course['course_name'] ?? ''); ?></h3>
            <div style="display:flex;align-items:center;gap:10px;">
                <span style="font-size:0.75rem;color:#94a3b8;">Career Fit Score</span>
                <span style="font-size:1.5rem;font-weight:700;color:#f59e0b;line-height:1;"><?php echo number_format($cPct, 1); ?>%</span>
            </div>
        </div>
        <button class="rcm-close" id="rcmClose" aria-label="Close" <?php echo isset($isAjax) && $isAjax ? 'onclick="document.getElementById(\'rcCourseModal\').close();"' : ''; ?>>
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>

    <!-- Body -->
    <div class="rcm-body">

        <!-- Progress bar -->
        <div class="rc-progress-bar" style="margin-bottom:1rem;">
            <div class="rc-progress-fill" style="width:<?php echo min(100, max(0, $cPct)); ?>%;"></div>
        </div>

        <!-- Score breakdown bars -->
        <?php if ($cBreakdown): ?>
        <div class="rcm-breakdown-block">
            <p class="rcm-breakdown-title">
                <i class="fa-solid fa-chart-bar"></i> Score Breakdown
            </p>

            <div class="rcm-bar-row">
                <div class="rcm-bar-meta">
                    <span class="rcm-bar-label">Cluster Alignment</span>
                    <span class="rcm-bar-pct"><?php echo $cClusterPct; ?>%</span>
                </div>
                <div class="rcm-bar-track">
                    <div class="rcm-bar-fill cluster" style="width:<?php echo min(100, max(0, $cClusterPct)); ?>%;"></div>
                </div>
            </div>

            <div class="rcm-bar-row">
                <div class="rcm-bar-meta">
                    <span class="rcm-bar-label">Competency Match</span>
                    <span class="rcm-bar-pct"><?php echo $cSkillsPct; ?>%</span>
                </div>
                <div class="rcm-bar-track">
                    <div class="rcm-bar-fill" style="width:<?php echo min(100, max(0, $cSkillsPct)); ?>%; background:linear-gradient(90deg,#ef4444,#f87171);"></div>
                </div>
            </div>

            <div class="rcm-bar-row">
                <div class="rcm-bar-meta">
                    <span class="rcm-bar-label">Career Fit</span>
                    <span class="rcm-bar-pct"><?php echo $cCareerPct; ?>%</span>
                </div>
                <div class="rcm-bar-track">
                    <div class="rcm-bar-fill" style="width:<?php echo min(100, max(0, $cCareerPct)); ?>%; background:linear-gradient(90deg,#f59e0b,#fbbf24);"></div>
                </div>
            </div>

            <div class="rcm-bar-row">
                <div class="rcm-bar-meta">
                    <span class="rcm-bar-label">Personality Match</span>
                    <span class="rcm-bar-pct"><?php echo $cPersonPct; ?>%</span>
                </div>
                <div class="rcm-bar-track">
                    <div class="rcm-bar-fill" style="width:<?php echo min(100, max(0, $cPersonPct)); ?>%; background:linear-gradient(90deg,#10b981,#34d399);"></div>
                </div>
            </div>

            <div class="rcm-bar-row">
                <div class="rcm-bar-meta">
                    <span class="rcm-bar-label">Strand Alignment</span>
                    <span class="rcm-bar-pct"><?php echo $cStrandPct; ?>%</span>
                </div>
                <div class="rcm-bar-track">
                    <div class="rcm-bar-fill" style="width:<?php echo min(100, max(0, $cStrandPct)); ?>%; background:linear-gradient(90deg,#8b5cf6,#a78bfa);"></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Why this score? -->
        <?php if (!empty($cExpl)): ?>
        <button class="rcm-why-btn" onclick="rcmToggleWhy(this)">
            <i class="fa-solid fa-circle-question"></i>
            Why this score?
            <i class="fa-solid fa-chevron-down rcm-chevron"></i>
        </button>
        <div class="rcm-why-content">
            <?php echo generateScoreExplanation($cExpl, $cSkillsPct, $cCareerPct, $cPersonPct, $cStrandPct, $cPct); ?>
        </div>
        <?php endif; ?>

        <!-- Description -->
        <?php if ($cDesc): ?>
        <p class="rc-course-desc"><?php echo nl2br(htmlspecialchars($cDesc)); ?></p>
        <?php endif; ?>

        <!-- Counselor View: View Answers Link -->
        <?php if (isset($isCounselorView) && $isCounselorView && isset($studentId)): ?>
        <hr class="rcm-divider">
        <div style="text-align: center; margin: 15px 0;">
            <a href="admin-assessments.php?student_id=<?php echo (int)$studentId; ?>" style="display:inline-flex; align-items:center; gap:8px; padding:12px 28px; font-size:0.95rem; font-weight:600; color:#fff; background:linear-gradient(90deg, #f59e0b, #fb923c); border-radius:30px; text-decoration:none; box-shadow:0 4px 12px rgba(245,158,11,0.25); transition:all 0.2s ease;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 16px rgba(245,158,11,0.4)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(245,158,11,0.25)';">
                <i class="fa-solid fa-clipboard-check"></i> View Full Answers
            </a>
            <p style="font-size:0.75rem; color:#64748b; margin-top:8px;">View the raw question-level answers for this student.</p>
        </div>
        <?php endif; ?>

        <!-- Possible careers / job tags -->
        <?php if (!empty($cJobs)): ?>
        <hr class="rcm-divider">
        <p class="rcm-section-label"><i class="fa-solid fa-briefcase" style="margin-right:5px;"></i>Possible Careers</p>
        <div class="rc-job-tags" style="margin-bottom:0;">
            <?php foreach ($cJobs as $job): ?>
            <span class="rc-job-tag" title="<?php echo htmlspecialchars($job['description'] ?? ''); ?>">
                <?php echo htmlspecialchars($job['job_title']); ?>
            </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Schools list -->
        <?php 
        $cDisplaySchools = [];
        $cIsAlt = false;
        
        if (!empty($cInDistrict)) {
            $cDisplaySchools = $cInDistrict; // Don't slice in modal, show all? Wait, previous code just looped all. Let's slice to 5 like others? Or show all in modal? Usually modals show all. We'll leave it uncapped or capped if we want. Actually, let's cap to 5 like the others for consistency. Wait, the old code looped $cSchools directly. I'll cap to 5. No, I'll show all. Wait, getRecommendedSchools already doesn't cap the arrays anymore, it returns all. Let's cap at 5 for the modal too, or maybe not. The old code just looped $cSchools. If we removed the cap from `getRecommendedSchools`, we should probably cap it here, or let the user click "View All". The modal doesn't have a View All button. Let's show all in modal.
            $cDisplaySchools = $cInDistrict;
        } elseif (!empty($cAltSchools)) {
            $cDisplaySchools = $cAltSchools;
            $cIsAlt = true;
        }
        ?>

        <?php if (!empty($cInDistrict) || !$cHasRegion): ?>
        <hr class="rcm-divider">
        <p class="rcm-section-label"><i class="fa-solid fa-school" style="margin-right:5px;"></i>Schools offering this course</p>
        <?php elseif ($cIsAlt): ?>
        <hr class="rcm-divider">
        <p style="color:#f87171; font-size:0.85rem; font-style:italic; margin-bottom: 0.5rem; background: rgba(248, 113, 113, 0.1); padding: 0.5rem; border-radius: 6px;">
            <?php $lbl = function_exists('formatDistrictLabel') ? formatDistrictLabel($cSelRegion) : htmlspecialchars($cSelRegion); ?>
            <i class="fa-solid fa-circle-info"></i> No schools in <?php echo $lbl; ?> offer this course.
        </p>
        <p class="rcm-section-label"><i class="fa-solid fa-school" style="margin-right:5px;"></i>Alternative Schools in Pangasinan</p>
        <?php endif; ?>

        <?php if (!empty($cDisplaySchools)): ?>
            <?php foreach ($cDisplaySchools as $school): ?>
            <div class="rc-school-row">
                <div class="rc-school-row-left">
                    <div class="rc-school-icon">
                        <?php if (!empty($school['logo']) && file_exists(__DIR__ . '/../' . $school['logo'])): ?>
                            <img src="<?php echo htmlspecialchars($school['logo']); ?>" alt="">
                        <?php else: ?>
                            <i class="fa-solid fa-school"></i>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="rc-school-name"><?php echo htmlspecialchars($school['name']); ?></div>
                        <div class="rc-school-location">
                            <?php echo htmlspecialchars($school['city'] ?? ($school['address'] ?? '')); ?>
                            <?php if (!empty($school['province'])): ?>, <?php echo htmlspecialchars($school['province']); ?><?php endif; ?>
                        </div>
                        <div class="rc-school-meta" style="display:flex; gap: 6px; margin-top: 5px;">
                            <?php if (!empty($school['type'])): ?>
                            <span style="font-size:0.65rem; background:rgba(59,130,246,0.1); color:#60a5fa; padding:2px 6px; border-radius:4px; font-weight:600;"><i class="fa-solid fa-building-columns" style="margin-right:2px;"></i> <?php echo htmlspecialchars($school['type']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($school['district_name'])): ?>
                            <span style="font-size:0.65rem; background:rgba(168,85,247,0.1); color:#c084fc; padding:2px 6px; border-radius:4px; font-weight:600;"><i class="fa-solid fa-map-location-dot" style="margin-right:2px;"></i> <?php echo htmlspecialchars($school['district_name']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php if (!empty($school['website'])): ?>
                <a href="<?php echo htmlspecialchars($school['website']); ?>" target="_blank" rel="noopener" class="rc-view-btn">View</a>
                <?php else: ?>
                <span class="rc-view-btn" style="opacity:0.4;cursor:default;">View</span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="rcm-no-schools">
                <?php echo nl2br(htmlspecialchars($course['school_recommendations']['message'] ?? 'No schools currently listed for this course.')); ?>
            </p>
        <?php endif; ?>

    </div><!-- /.rcm-body -->
</div><!-- /.rc-modal-payload -->
